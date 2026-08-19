<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Heuristic;

class RegionKey
{
    /** @var array<string, list<string>> */
    private const GROUPS = [
        'NA' => ['US', 'CA', 'MX'],
        'EU' => [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU',
            'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES',
            'SE', 'GB', 'CH', 'NO', 'IS', 'LI',
        ],
        'APAC' => ['AU', 'NZ', 'JP', 'KR', 'CN', 'HK', 'TW', 'SG', 'MY', 'TH', 'VN', 'IN', 'PH', 'ID'],
        'LATAM' => ['BR', 'AR', 'CL', 'CO', 'PE', 'EC', 'UY', 'PY', 'BO', 'CR', 'PA', 'GT'],
    ];

    public function fromAddress(string $countryCode, string $postcode): string
    {
        $country = strtoupper(trim($countryCode));
        if ($country === '') {
            $country = 'US';
        }
        $prefix = $this->postalPrefix($postcode);
        return $country . '-' . ($prefix !== '' ? $prefix : 'UNK');
    }

    public function country(string $region): string
    {
        if (preg_match('/^([A-Z]{2})-/i', $region, $matches) === 1) {
            return strtoupper($matches[1]);
        }
        return 'US';
    }

    public function group(string $countryCode): string
    {
        $country = strtoupper($countryCode);
        foreach (self::GROUPS as $group => $countries) {
            if (in_array($country, $countries, true)) {
                return $group;
            }
        }
        return $country !== '' ? $country : 'INTL';
    }

    public function postalPrefix(string $postcode): string
    {
        $compact = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $postcode));
        return substr($compact, 0, 3);
    }

    public function zipDistance(string $regionA, string $regionB): int
    {
        $a = (int) substr($this->postalPrefix(explode('-', $regionA, 2)[1] ?? $regionA), 0, 3);
        $b = (int) substr($this->postalPrefix(explode('-', $regionB, 2)[1] ?? $regionB), 0, 3);
        return abs($a - $b);
    }
}
