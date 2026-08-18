<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Rate;

class UspsLookupResult
{
    public const WORKED = 'worked';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(
        public readonly string $status,
        public readonly string $detail = ''
    ) {
    }

    public function worked(): bool
    {
        return $this->status === self::WORKED || $this->status === self::PARTIAL;
    }
}
