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

        $fingerprint = $this->fingerprint($request, $storeId);
        $cached = $this->cache->load(self::CACHE_PREFIX . $fingerprint);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $apiKey = $this->config->getApiKey($storeId);
        $carrierIds = $this->config->getCarrierIds($storeId);
        $origin = $this->config->getOriginPostalCode($storeId);
        if ($apiKey === '' || $carrierIds === [] || $origin === '') {
            $this->logger->warning('Mulps_ShipStationLiveRates: live rates skipped, missing API key, carrier IDs, or origin ZIP');
            return null;
        }

        $dest = preg_replace('/\s+/', '', (string) $request->getDestPostcode()) ?? '';
        if ($dest === '') {
            return null;
        }

        $weightLb = max(0.1, (float) $request->getPackageWeight());
        $payload = [
            'rate_options' => [
                'carrier_ids' => $carrierIds,
            ],
            'shipment' => [
                'validate_address' => 'no_validation',
                'ship_from' => [
                    'postal_code' => $origin,
                    'country_code' => 'US',
                ],
                'ship_to' => [
                    'postal_code' => $dest,
                    'country_code' => $request->getDestCountryId() ?: 'US',
                    'state_province' => (string) $request->getDestRegionCode(),
                    'city_locality' => (string) $request->getDestCity(),
                    'address_line1' => (string) $request->getDestStreet(),
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

        try {
            $body = $this->postJson('/rates', $payload, $storeId);
        } catch (RuntimeException $e) {
            $this->logger->error('Mulps_ShipStationLiveRates live rate failed: ' . $e->getMessage());
            $this->cache->save('1', self::CIRCUIT_KEY, ['MULPS_SSLR'], 120);
            return null;
        }

        $rateResponse = is_array($body['rate_response'] ?? null) ? $body['rate_response'] : $body;
        $rates = $rateResponse['rates'] ?? [];
        if (!is_array($rates)) {
            $rates = [];
        }

        $result = [
            'rates' => array_values(array_filter($rates, 'is_array')),
            'rate_response' => $rateResponse,
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

    private function fingerprint(RateRequest $request, ?int $storeId): string
    {
        $parts = [
            (string) $request->getDestPostcode(),
            (string) $request->getDestCountryId(),
            (string) $request->getPackageWeight(),
            $this->config->getOriginPostalCode($storeId),
            implode(',', $this->config->getCarrierIds($storeId)),
        ];
        return hash('sha256', implode('|', $parts));
    }
}
