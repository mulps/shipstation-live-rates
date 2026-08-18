<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Heuristic;

class ContentsBucket
{
    public function fromWeightLb(float $weightLb): string
    {
        $oz = (int) ceil($weightLb * 16);
        if ($oz <= 16) {
            return '0-1lb';
        }
        if ($oz <= 80) {
            return '1-5lb';
        }
        if ($oz <= 160) {
            return '5-10lb';
        }
        if ($oz <= 320) {
            return '10-20lb';
        }
        return '20lb+';
    }
}
