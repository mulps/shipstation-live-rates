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
        } catch (RuntimeException $e) {
            $this->logger->error('Mulps_ShipStationLiveRates carrier list failed: ' . $e->getMessage());
            $this->cache->save('1', self::CIRCUIT_KEY, ['MULPS_SSLR'], 120);
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

        $weightLb = max(0.1, (float) $request->getPackageWeight());

        try {
            $body = $this->postJson(
                '/rates',
                $this->fullRatePayload($request, $carrierIds, $origin, $dest, $weightLb, $storeId),
                $storeId
            );
        } catch (RuntimeException $e) {
            $this->logger->error('Mulps_ShipStationLiveRates live rate failed: ' . $e->getMessage());
            $this->cache->save('1', self::CIRCUIT_KEY, ['MULPS_SSLR'], 120);
            return null;
        }

        $rateResponse = is_array($body['rate_response'] ?? null) ? $body['rate_response'] : $body;
        $rates = $rateResponse['rates'] ?? $body['rates'] ?? $body;
        if (!is_array($rates)) {
            $rates = [];
        }
        $rates = array_values(array_filter($rates, 'is_array'));
        if ($rates !== [] && !isset($rates[0]['shipping_amount']) && isset($rates[0][0]) && is_array($rates[0][0])) {
            $rates = $rates[0];
        }

        $result = [
            'rates' => $rates,
            'rate_response' => is_array($rateResponse) ? $rateResponse : ['rates' => $rates],
        ];

        $ttl = $this->config->getLiveCacheTtl($storeId);
        if ($ttl > 0 && $result['rates'] !== []) {
            $this->cache->save(
                json_encode($result, JSON_THROW_ON_ERROR),
                self::CACHE_PREFIX . $fingerprint,
                ['MULPS_SSLR'],
                $ttl
            );
        }

        return $result;
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
    public function getJson(string $pathWithQuery, ?int $storeId = null): array
    {
        $curl = $this->curlFactory->create();
        $timeout = $this->config->getTimeoutSeconds($storeId);
        $curl->setOption(CURLOPT_TIMEOUT, $timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $timeout);
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
        $timeout = $this->config->getTimeoutSeconds($storeId);
        $curl->setOption(CURLOPT_TIMEOUT, $timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $timeout);
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('API-Key', $this->config->getApiKey($storeId));
        $url = $this->config->getApiBaseUrl($storeId) . $path;
        $curl->post($url, json_encode($payload, JSON_THROW_ON_ERROR));
        return $this->decodeResponse($curl);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Curl $curl): array
    {
        $status = (int) $curl->getStatus();
        $response = (string) $curl->getBody();
        if ($status === 429 || $status >= 500 || $status === 0) {
            throw new RuntimeException('HTTP ' . $status);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Non-JSON ShipStation response HTTP ' . $status);
        }
        if ($status >= 400) {
            throw new RuntimeException('HTTP ' . $status . ' ' . substr($response, 0, 300));
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
            (string) $request->getPackageWeight(),
            $this->config->getOriginPostalCode($storeId),
            implode(',', $carrierIds),
        ];
        return hash('sha256', implode('|', $parts));
    }
}
