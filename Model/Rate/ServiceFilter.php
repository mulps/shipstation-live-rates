<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Rate;

use Mulps\ShipStationLiveRates\Model\ModuleConfig;

class ServiceFilter
{
    public function __construct(
        private readonly ModuleConfig $config
    ) {
    }

    /**
     * @param array<string, mixed> $rateOrLabel
     */
    public function isExcluded(array $rateOrLabel, ?int $storeId = null): bool
    {
        return $this->isExcludedService((string) ($rateOrLabel['service_code'] ?? ''), $storeId);
    }

    public function isExcludedService(string $serviceCode, ?int $storeId = null): bool
    {
        $code = strtolower(trim($serviceCode));
        if ($code === '') {
            return false;
        }
        foreach ($this->config->getDisabledServiceCodes($storeId) as $disabled) {
            if (strtolower($disabled) === $code) {
                return true;
            }
        }
        return false;
    }
}
