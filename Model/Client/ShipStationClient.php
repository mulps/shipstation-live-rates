<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Client;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Mulps\ShipStationLiveRates\Model\ModuleConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ShipStationClient
{
    private const CACHE_PREFIX = 'mulps_sslr_live_';
    private const CIRCUIT_KEY = 'mulps_sslr_circuit';
    private const LAST_GOOD_PREFIX = 'mulps_sslr_lastgood_';

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly CurlFactory $curlFactory,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{rates: list<array<string, mixed>>, rate_response: array<string, mixed>}|null
     */
    public function getRates(RateRequest $request, ?int $storeId = null): ?array
    {
        if ($this->cache->load(self::CIRCUIT_KEY)) {
            $this->logger->warning('Mulps_ShipStationLiveRates: circuit open, skipping live rates');
            return null;
        }

        $apiKey = $this->config->getApiKey($storeId);
        $origin = $this->config->getOriginPostalCode($storeId);
        if ($apiKey === '' || $origin === '') {
            $this->logger->warning('Mulps_ShipStationLiveRates: live rates skipped, missing API key or origin ZIP');
            return null;
        }

        try {
            $carrierIds = $this->resolveCarrierIds($storeId);
        } catch (ShipStationHttpException $e) {
            $this->logger->error('Mulps_ShipStationLiveRates carrier list failed: ' . $e->getMessage());
            if ($e->isRetryable()) {
                $this->cache->save('1', self::CIRCUIT_KEY, ['MULPS_SSLR'], 120);
            }
            return null;
        } catch (RuntimeException $e) {
            $this->logger->error('Mulps_ShipStationLiveRates carrier list failed: ' . $e->getMessage());
            return null;
        }
        if ($carrierIds === []) {
            $this->logger->warning('Mulps_ShipStationLiveRates: no ShipStation carriers available');
            return null;
        }

        $fingerprint = $this->fingerprint($request, $storeId, $carrierIds);
        $cached = $this->cache->load(self::CACHE_PREFIX . $fingerprint);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $dest = preg_replace('/\s+/', '', (string) $request->getDestPostcode()) ?? '';
        if ($dest === '') {
            return null;
        }

        $weightLb = $this->config->billedWeightLb((float) $request->getPackageWeight(), $storeId);
        $destStreet = trim((string) $request->getDestStreet());

        try {
            if ($destStreet === '') {
                $body = $this->postJson('/rates/estimate', $this->estimatePayload($request, $carrierIds, $origin, $dest, $weightLb, $storeId), $storeId);
            } else {
                try {
                    $body = $this->postJson('/rates', $this->fullRatePayload($request, $carrierIds, $origin, $dest, $weightLb, $storeId), $storeId);
                } catch (ShipStationHttpException $e) {
                    if ($e->isRetryable()) {
                        throw $e;
                    }
                    $this->logger->warning('Mulps_ShipStationLiveRates full rate rejected, retrying ZIP estimate: ' . $e->getMessage());
                    $body = $this->postJson('/rates/estimate', $this->estimatePayload($request, $carrierIds, $origin, $dest, $weightLb, $storeId), $storeId);
                }
            }
        } catch (ShipStationHttpException $e) {
            $this->logger->error('Mulps_ShipStationLiveRates live rate failed: ' . $e->getMessage());
            if ($e->isRetryable()) {
                $this->cache->save('1', self::CIRCUIT_KEY, ['MULPS_SSLR'], 120);
            }
            return null;
        } catch (RuntimeException $e) {
            $this->logger->error('Mulps_ShipStationLiveRates live rate failed: ' . $e->getMessage());
            return null;
        }

        $rates = $this->extractRates($body);
        $result = [
            'rates' => $rates,
            'rate_response' => [
                'rates' => $rates,
                'status' => $rates !== [] ? 'completed' : 'error',
            ],
        ];

        $ttl = $this->config->getLiveCacheTtl($storeId);
        if ($ttl > 0 && $result['rates'] !== []) {
            $this->cache->save(
                json_encode($result, JSON_THROW_ON_ERROR),
                self::CACHE_PREFIX . $fingerprint,
                ['MULPS_SSLR'],
                $ttl
            );
            $this->rememberLastGood($result['rates'], $dest, $weightLb);
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|list<mixed> $body
     * @return list<array<string, mixed>>
     */
    private function extractRates(array $body): array
    {
        $nested = $body['rate_response']['rates'] ?? $body['rates'] ?? null;
        if (!is_array($nested) && array_is_list($body)) {
            $nested = $body;
        }
        if (!is_array($nested)) {
            return [];
        }
        $rates = [];
        foreach ($nested as $row) {
            if (is_array($row) && isset($row['shipping_amount'])) {
                $rates[] = $row;
            }
        }
        return $rates;
    }

    /**
     * Admin list if set; otherwise every usable carrier from GET /v2/carriers (cached).
     *
     * @return list<string>
     */
    public function resolveCarrierIds(?int $storeId = null): array
    {
        $configured = $this->config->getCarrierIds($storeId);
        if ($configured !== []) {
            return $configured;
        }

        $cacheKey = 'mulps_sslr_carriers_' . sha1($this->config->getApiBaseUrl($storeId));
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && $decoded !== []) {
                return array_values(array_filter($decoded, 'is_string'));
            }
        }

        $body = $this->getJson('/carriers', $storeId);
        $ids = [];
        $carriers = $body['carriers'] ?? [];
        if (is_array($carriers)) {
            foreach ($carriers as $carrier) {
                if (!is_array($carrier)) {
                    continue;
                }
                if (!empty($carrier['disabled_by_billing_plan'])) {
                    continue;
                }
                $id = (string) ($carrier['carrier_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            $this->cache->save(json_encode($ids, JSON_THROW_ON_ERROR), $cacheKey, ['MULPS_SSLR'], 3600);
        }
        return $ids;
    }

    /**
     * Connected carrier services for admin (label + service_code). Cached. Does not open the live-rate circuit.
     *
     * @return list<array{code: string, label: string}>
     */
    public function listServiceCatalog(?int $storeId = null): array
    {
        $cacheKey = 'mulps_sslr_service_catalog_' . sha1($this->config->getApiBaseUrl($storeId) . '|' . $this->config->getApiKey($storeId));
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $this->normalizeServiceCatalog($decoded);
            }
        }

        if ($this->config->getApiKey($storeId) === '') {
            return [];
        }

        try {
            $body = $this->getJson('/carriers', $storeId, 15);
        } catch (\Throwable $e) {
            $this->logger->warning('Mulps_ShipStationLiveRates service catalog failed: ' . $e->getMessage());
            return [];
        }

        $carriers = $body['carriers'] ?? null;
        if (!is_array($carriers) && array_is_list($body)) {
            $carriers = $body;
        }
        if (!is_array($carriers)) {
            $carriers = [];
        }
        $catalog = [];
        foreach ($carriers as $carrier) {
            if (!is_array($carrier)) {
                continue;
            }
            if (!empty($carrier['disabled_by_billing_plan'])) {
                continue;
            }
            $carrierId = (string) ($carrier['carrier_id'] ?? '');
            $carrierLabel = (string) ($carrier['nickname'] ?? $carrier['friendly_name'] ?? $carrier['carrier_code'] ?? $carrierId);
            $services = $carrier['services'] ?? null;
            if (!is_array($services) || $services === []) {
                if ($carrierId === '') {
                    continue;
                }
                try {
                    $extra = $this->getJson('/carriers/' . rawurlencode($carrierId) . '/services', $storeId, 15);
                    $services = $extra['services'] ?? [];
                } catch (\Throwable $e) {
                    $this->logger->warning('Mulps_ShipStationLiveRates services for ' . $carrierId . ' failed: ' . $e->getMessage());
                    $services = [];
                }
            }
            if (!is_array($services)) {
                continue;
            }
            foreach ($services as $service) {
                if (!is_array($service)) {
                    continue;
                }
                $code = (string) ($service['service_code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $name = (string) ($service['name'] ?? $code);
                $catalog[$code] = [
                    'code' => $code,
                    'label' => $carrierLabel . ' — ' . $name . ' (' . $code . ')',
                ];
            }
        }
        $rows = array_values($catalog);
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        if ($rows !== []) {
            $this->cache->save(json_encode($rows, JSON_THROW_ON_ERROR), $cacheKey, ['MULPS_SSLR'], 3600);
        }
        return $rows;
    }

    /**
     * @param mixed $decoded
     * @return list<array{code: string, label: string}>
     */
    private function normalizeServiceCatalog(mixed $decoded): array
    {
        $rows = [];
        if (!is_array($decoded)) {
            return [];
        }
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? '');
            $label = (string) ($row['label'] ?? $code);
            if ($code === '') {
                continue;
            }
            $rows[] = ['code' => $code, 'label' => $label];
        }
        return $rows;
    }

    /**
     * Cart estimate: ZIP/state/weight only. ShipStation does not require street here.
     *
     * @param list<string> $carrierIds
     * @return array<string, mixed>
     */
    private function estimatePayload(
        RateRequest $request,
        array $carrierIds,
        string $origin,
        string $dest,
        float $weightLb,
        ?int $storeId
    ): array {
        return [
            'carrier_ids' => $carrierIds,
            'from_country_code' => $this->config->getOriginCountry($storeId),
            'from_postal_code' => $origin,
            'to_country_code' => $request->getDestCountryId() ?: 'US',
            'to_postal_code' => $dest,
            'to_city_locality' => (string) $request->getDestCity(),
            'to_state_province' => (string) $request->getDestRegionCode(),
            'weight' => [
                'value' => $weightLb,
                'unit' => 'pound',
            ],
            'confirmation' => 'none',
            'address_residential_indicator' => 'unknown',
        ];
    }

    /**
     * Checkout quote with a street. no_validation so unofficial addresses still rate.
     *
     * @param list<string> $carrierIds
     * @return array<string, mixed>
     */
    private function fullRatePayload(
        RateRequest $request,
        array $carrierIds,
        string $origin,
        string $dest,
        float $weightLb,
        ?int $storeId
    ): array {
        $fromStreet = $this->config->getOriginStreet($storeId);
        $fromCity = $this->config->getOriginCity($storeId);
        $toStreet = trim((string) $request->getDestStreet());
        $toCity = trim((string) $request->getDestCity());
        $toState = (string) $request->getDestRegionCode();
        $toLine1 = $toStreet !== '' ? $toStreet : ($toCity !== '' ? $toCity : $dest);
        $toLocality = $toCity !== '' ? $toCity : ($toState !== '' ? $toState : $dest);

        return [
            'rate_options' => [
                'carrier_ids' => $carrierIds,
            ],
            'shipment' => [
                'validate_address' => 'no_validation',
                'ship_from' => [
                    'name' => $this->config->getOriginName($storeId),
                    'phone' => '000-000-0000',
                    'address_line1' => $fromStreet !== '' ? $fromStreet : $origin,
                    'city_locality' => $fromCity !== '' ? $fromCity : $origin,
                    'postal_code' => $origin,
                    'country_code' => $this->config->getOriginCountry($storeId),
                ],
                'ship_to' => [
                    'name' => 'Customer',
                    'phone' => '000-000-0000',
                    'address_line1' => $toLine1,
                    'city_locality' => $toLocality,
                    'state_province' => $toState,
                    'postal_code' => $dest,
                    'country_code' => $request->getDestCountryId() ?: 'US',
                ],
                'packages' => [
                    [
                        'weight' => [
                            'value' => $weightLb,
                            'unit' => 'pound',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $pathWithQuery, ?int $storeId = null, ?int $timeoutSeconds = null): array
    {
        $curl = $this->curlFactory->create();
        $this->applyTimeout($curl, $timeoutSeconds ?? $this->config->getTimeoutSeconds($storeId));
        $curl->addHeader('API-Key', $this->config->getApiKey($storeId));
        $url = $this->config->getApiBaseUrl($storeId) . $pathWithQuery;
        $curl->get($url);
        return $this->decodeResponse($curl);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $payload, ?int $storeId = null): array
    {
        $curl = $this->curlFactory->create();
        $this->applyTimeout($curl, $this->config->getTimeoutSeconds($storeId));
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('API-Key', $this->config->getApiKey($storeId));
        $url = $this->config->getApiBaseUrl($storeId) . $path;
        $curl->post($url, json_encode($payload, JSON_THROW_ON_ERROR));
        return $this->decodeResponse($curl);
    }

    private function applyTimeout(Curl $curl, int $timeoutSeconds): void
    {
        $timeoutSeconds = max(0, $timeoutSeconds);
        if (method_exists($curl, 'setTimeout')) {
            $curl->setTimeout($timeoutSeconds);
        }
        $curl->setOption(CURLOPT_TIMEOUT, $timeoutSeconds);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $timeoutSeconds === 0 ? 30 : min(20, max(1, $timeoutSeconds)));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Curl $curl): array
    {
        $status = (int) $curl->getStatus();
        $response = (string) $curl->getBody();
        if ($status === 429 || $status >= 500 || $status === 0) {
            throw new ShipStationHttpException('HTTP ' . $status, $status);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ShipStationHttpException('Non-JSON ShipStation response HTTP ' . $status, $status);
        }
        if ($status >= 400) {
            throw new ShipStationHttpException('HTTP ' . $status . ' ' . substr($response, 0, 300), $status);
        }
        return $decoded;
    }

    /**
     * @param list<string> $carrierIds
     */
    private function fingerprint(RateRequest $request, ?int $storeId, array $carrierIds): string
    {
        $parts = [
            (string) $request->getDestPostcode(),
            (string) $request->getDestCountryId(),
            (string) $this->config->billedWeightLb((float) $request->getPackageWeight(), $storeId),
            $this->config->getOriginPostalCode($storeId),
            implode(',', $carrierIds),
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param list<array<string, mixed>> $rates
     */
    private function rememberLastGood(array $rates, string $dest, float $weightLb): void
    {
        $cheapest = null;
        foreach ($rates as $rate) {
            $amount = $rate['shipping_amount']['amount'] ?? null;
            if (!is_numeric($amount)) {
                continue;
            }
            $value = (float) $amount;
            $cheapest = $cheapest === null ? $value : min($cheapest, $value);
        }
        if ($cheapest === null) {
            return;
        }
        $payload = (string) $cheapest;
        $this->cache->save($payload, self::LAST_GOOD_PREFIX . 'any', ['MULPS_SSLR'], 604800);
        $zip3 = substr(preg_replace('/\D+/', '', $dest) ?? '', 0, 3);
        if ($zip3 !== '') {
            $this->cache->save($payload, self::LAST_GOOD_PREFIX . $zip3, ['MULPS_SSLR'], 604800);
        }
        unset($weightLb);
    }

    public function lastGoodAmount(string $destPostcode): ?float
    {
        $zip3 = substr(preg_replace('/\D+/', '', $destPostcode) ?? '', 0, 3);
        foreach ([$zip3, 'any'] as $suffix) {
            if ($suffix === '') {
                continue;
            }
            $cached = $this->cache->load(self::LAST_GOOD_PREFIX . $suffix);
            if (is_string($cached) && is_numeric($cached)) {
                return (float) $cached;
            }
        }
        return null;
    }
}
