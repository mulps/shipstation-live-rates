<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Rate;

class UspsLookupDetector
{
    /**
     * @param array<string, mixed> $rateResponse
     */
    public function detect(array $rateResponse, bool $uspsCarrierConfigured): UspsLookupResult
    {
        if (!$uspsCarrierConfigured) {
            return new UspsLookupResult(UspsLookupResult::NOT_CONFIGURED, 'No USPS-family carrier IDs configured');
        }

        $status = (string) ($rateResponse['status'] ?? '');
        $rates = $rateResponse['rates'] ?? [];
        $invalid = $rateResponse['invalid_rates'] ?? [];
        $errors = $rateResponse['errors'] ?? [];

        $validUsps = 0;
        $invalidUsps = 0;
        if (is_array($rates)) {
            foreach ($rates as $rate) {
                if (is_array($rate) && $this->isUspsFamily($rate)) {
                    $validation = (string) ($rate['validation_status'] ?? 'valid');
                    if ($validation === 'invalid') {
                        $invalidUsps++;
                    } else {
                        $validUsps++;
                    }
                }
            }
        }
        if (is_array($invalid)) {
            foreach ($invalid as $rate) {
                if (is_array($rate) && $this->isUspsFamily($rate)) {
                    $invalidUsps++;
                }
            }
        }

        foreach (is_array($errors) ? $errors : [] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $code = (string) ($error['error_code'] ?? '');
            $carrier = strtolower((string) ($error['carrier_code'] ?? ''));
            if ($code === 'no_rates_returned' && $this->isUspsCarrierCode($carrier)) {
                return new UspsLookupResult(UspsLookupResult::FAILED, $code);
            }
        }

        if ($validUsps > 0 && $invalidUsps > 0) {
            return new UspsLookupResult(UspsLookupResult::PARTIAL, 'status=' . $status);
        }
        if ($validUsps > 0) {
            return new UspsLookupResult(UspsLookupResult::WORKED, 'status=' . $status);
        }
        if ($status === 'error' || $invalidUsps > 0) {
            return new UspsLookupResult(UspsLookupResult::FAILED, 'status=' . $status);
        }

        return new UspsLookupResult(UspsLookupResult::FAILED, 'no USPS-family rates');
    }

    /**
     * @param array<string, mixed> $rate
     */
    private function isUspsFamily(array $rate): bool
    {
        $service = strtolower((string) ($rate['service_code'] ?? ''));
        $carrier = strtolower((string) ($rate['carrier_code'] ?? ''));
        return str_starts_with($service, 'usps_') || $this->isUspsCarrierCode($carrier);
    }

    private function isUspsCarrierCode(string $carrier): bool
    {
        return in_array($carrier, ['usps', 'stamps_com', 'endicia'], true);
    }
}
