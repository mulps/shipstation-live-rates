<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Rate;

use Mulps\ShipStationLiveRates\Model\ModuleConfig;

class MarkupApplier
{
    public function __construct(private readonly ModuleConfig $config)
    {
    }

    public function apply(float $rawAmount, string $destPostcode, ?int $storeId = null, bool $domestic = true): float
    {
        $zip3 = $this->zip3($destPostcode);
        $map = $this->config->getRegionalMarkupMap($storeId);
        $percent = $map[$zip3] ?? $this->config->getGlobalMarkupPercent($storeId);
        $padded = $rawAmount * (1 + $percent / 100);
        if ($domestic) {
            $floor = $this->config->getPriceFloor($storeId);
            if ($floor > 0) {
                $padded = max($floor, $padded);
            }
        }
        $ceiling = $this->config->getPriceCeiling($storeId);
        if ($ceiling > 0) {
            $padded = min($ceiling, $padded);
        }
        return round(max(0, $padded), 2);
    }

    public function zip3(string $postcode): string
    {
        $digits = preg_replace('/\D+/', '', $postcode) ?? '';
        return substr($digits, 0, 3);
    }
}
