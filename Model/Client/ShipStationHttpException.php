<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Client;

use RuntimeException;

class ShipStationHttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRetryable(): bool
    {
        $status = $this->httpStatus;
        return $status === 0 || $status === 429 || $status >= 500;
    }
}
