<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class ModuleConfig
{
    public const CARRIER_CODE = 'ssliverates';
    private const XML = 'carriers/' . self::CARRIER_CODE . '/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML . 'active', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML . 'title', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getMethodName(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML . 'name', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiKey(?int $storeId = null): string
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML . 'api_key', ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === '') {
            return '';
        }
        $decrypted = $this->encryptor->decrypt($raw);
        return $decrypted !== '' ? $decrypted : $raw;
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->scopeConfig->getValue(self::XML . 'api_base_url', ScopeInterface::SCOPE_STORE, $storeId), '/');
    }

    /**
     * @return list<string>
     */
    public function getCarrierIds(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML . 'carrier_ids', ScopeInterface::SCOPE_STORE, $storeId);
        $ids = array_map('trim', explode(',', $raw));
        return array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));
    }

    public function getOriginPostalCode(?int $storeId = null): string
    {
        $override = (string) $this->scopeConfig->getValue(self::XML . 'origin_postal_code', ScopeInterface::SCOPE_STORE, $storeId);
        if ($override !== '') {
            return $override;
        }
        return (string) $this->scopeConfig->getValue('shipping/origin/postcode', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getOriginCountry(?int $storeId = null): string
    {
        $country = (string) $this->scopeConfig->getValue('shipping/origin/country_id', ScopeInterface::SCOPE_STORE, $storeId);
        return $country !== '' ? $country : 'US';
    }

    public function getOriginCity(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue('shipping/origin/city', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getOriginStreet(?int $storeId = null): string
    {
        $street = $this->scopeConfig->getValue('shipping/origin/street_line1', ScopeInterface::SCOPE_STORE, $storeId);
        if (is_array($street)) {
            return trim(implode(' ', $street));
        }
        return trim((string) $street);
    }

    public function getOriginName(?int $storeId = null): string
    {
        $name = (string) $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $storeId);
        return $name !== '' ? $name : 'Warehouse';
    }

    public function getTimeoutSeconds(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML . 'timeout_seconds', ScopeInterface::SCOPE_STORE, $storeId);
        return max(1, $value);
    }

    public function getLiveCacheTtl(?int $storeId = null): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML . 'live_cache_ttl', ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getGlobalMarkupPercent(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML . 'global_markup_percent', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @return array<string, float>
     */
    public function getRegionalMarkupMap(?int $storeId = null): array
    {
        $json = (string) $this->scopeConfig->getValue(self::XML . 'regional_markup_json', ScopeInterface::SCOPE_STORE, $storeId);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $zip3 => $percent) {
            $map[(string) $zip3] = (float) $percent;
        }
        return $map;
    }

    public function getPriceFloor(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML . 'price_floor', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getPriceCeiling(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML . 'price_ceiling', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function showEstimatedTitle(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML . 'show_estimated_title', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getHeuristicMinSamples(?int $storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML . 'heuristic_min_samples', ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getLookbackDays(?int $storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML . 'lookback_days', ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getDefaultWeightLb(?int $storeId = null): float
    {
        $value = (float) $this->scopeConfig->getValue(self::XML . 'default_weight_lb', ScopeInterface::SCOPE_STORE, $storeId);
        return $value > 0 ? $value : 0.5;
    }

    public function getGuaranteedPrice(?int $storeId = null): float
    {
        $value = (float) $this->scopeConfig->getValue(self::XML . 'guaranteed_price', ScopeInterface::SCOPE_STORE, $storeId);
        $floor = $this->getPriceFloor($storeId);
        if ($value <= 0) {
            $value = $floor > 0 ? $floor : 10.0;
        }
        return $value;
    }

    public function getGuaranteedTitle(?int $storeId = null): string
    {
        $title = (string) $this->scopeConfig->getValue(self::XML . 'guaranteed_title', ScopeInterface::SCOPE_STORE, $storeId);
        return $title !== '' ? $title : 'Standard Shipping';
    }

    public function billedWeightLb(float $packageWeight, ?int $storeId = null): float
    {
        return $packageWeight > 0 ? $packageWeight : $this->getDefaultWeightLb($storeId);
    }
}
