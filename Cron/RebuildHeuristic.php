<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Cron;

use Mulps\ShipStationLiveRates\Model\Client\ShipStationClient;
use Mulps\ShipStationLiveRates\Model\Heuristic\ContentsBucket;
use Mulps\ShipStationLiveRates\Model\Heuristic\SnapshotRepository;
use Mulps\ShipStationLiveRates\Model\Heuristic\RegionKey;
use Mulps\ShipStationLiveRates\Model\ModuleConfig;
use Mulps\ShipStationLiveRates\Model\Rate\ServiceFilter;
use Psr\Log\LoggerInterface;

class RebuildHeuristic
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly ShipStationClient $client,
        private readonly SnapshotRepository $snapshot,
        private readonly ContentsBucket $contentsBucket,
        private readonly RegionKey $regionKey,
        private readonly ServiceFilter $serviceFilter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): int
    {
        set_time_limit(0);
        if ($this->config->getApiKey() === '') {
            $this->logger->info('Mulps_ShipStationLiveRates heuristic rebuild skipped: no API key');
            throw new \RuntimeException('No ShipStation API key is configured.');
        }

        $days = $this->config->getLookbackDays();
        $minSamples = $this->config->getHeuristicMinSamples();
        $end = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = $end->modify('-' . $days . ' days');
        /** @var array<string, list<float>> $groups */
        $groups = [];
        $page = 1;
        $pageSize = 100;
        $pages = 1;

        try {
            do {
                $query = http_build_query([
                    'created_at_start' => $start->format('Y-m-d\TH:i:s\Z'),
                    'created_at_end' => $end->format('Y-m-d\TH:i:s\Z'),
                    'page' => $page,
                    'page_size' => $pageSize,
                ]);
                $body = $this->client->getJson('/labels?' . $query, null, 0);
                $labels = $body['labels'] ?? [];
                $pages = (int) ($body['pages'] ?? $page);
                if (!is_array($labels)) {
                    break;
                }
                foreach ($labels as $label) {
                    if (!is_array($label)) {
                        continue;
                    }
                    $cost = $label['shipment_cost']['amount'] ?? null;
                    $country = (string) ($label['ship_to']['country_code'] ?? 'US');
                    $postcode = (string) ($label['ship_to']['postal_code'] ?? '');
                    $service = (string) ($label['service_code'] ?? 'default');
                    $weight = $this->labelWeightLb($label);
                    if (!is_numeric($cost) || $postcode === '' || $weight <= 0) {
                        continue;
                    }
                    if ($this->serviceFilter->isExcluded($label)) {
                        continue;
                    }
                    $region = $this->regionKey->fromAddress($country, $postcode);
                    $bucket = $this->contentsBucket->fromWeightLb($weight);
                    $key = $region . '|' . $bucket . '|' . $service;
                    $groups[$key][] = (float) $cost;
                }
                $page++;
                if ($page <= $pages) {
                    usleep(200000);
                }
            } while ($page <= $pages && $page <= 50);
        } catch (\Throwable $e) {
            $this->logger->error('Mulps_ShipStationLiveRates heuristic rebuild failed: ' . $e->getMessage());
            throw $e;
        }

        $cells = [];
        foreach ($groups as $key => $amounts) {
            sort($amounts);
            [$region, $bucket, $service] = explode('|', $key, 3);
            $cells[] = [
                'region' => $region,
                'contents_bucket' => $bucket,
                'service_code' => $service,
                'sample_count' => count($amounts),
                'median_amount' => $this->percentile($amounts, 0.50),
                'p75_amount' => $this->percentile($amounts, 0.75),
                'merged_p75_amount' => 0.0,
            ];
        }

        foreach ($cells as $i => $cell) {
            if ((int) $cell['sample_count'] >= $minSamples) {
                $cells[$i]['merged_p75_amount'] = (float) $cell['p75_amount'];
                continue;
            }
                $cells[$i]['merged_p75_amount'] = $this->neighborP75(
                    $cell,
                    $cells,
                    $minSamples,
                    $this->config->getOriginCountry()
                );
        }

        $this->snapshot->replaceAll($cells);
        $this->logger->info('Mulps_ShipStationLiveRates heuristic rebuild stored ' . count($cells) . ' cells');
        return count($cells);
    }

    /**
     * @param array<string, mixed> $label
     */
    private function labelWeightLb(array $label): float
    {
        $packages = $label['packages'] ?? [];
        if (!is_array($packages) || $packages === []) {
            return 0.0;
        }
        $weight = $packages[0]['weight'] ?? [];
        if (!is_array($weight) || !isset($weight['value'])) {
            return 0.0;
        }
        $value = (float) $weight['value'];
        $unit = strtolower((string) ($weight['unit'] ?? 'ounce'));
        return match ($unit) {
            'pound', 'pounds', 'lb', 'lbs' => $value,
            default => $value / 16,
        };
    }

    /**
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $idx = (int) floor(($n - 1) * $p);
        return round($sorted[$idx], 4);
    }

    /**
     * @param array{region:string,contents_bucket:string,service_code:string,sample_count:int,p75_amount:float} $cell
     * @param list<array{region:string,contents_bucket:string,service_code:string,sample_count:int,p75_amount:float}> $cells
     */
    private function neighborP75(array $cell, array $cells, int $minSamples, string $originCountry): float
    {
        $cellCountry = $this->regionKey->country($cell['region']);
        $cellGroup = $this->regionKey->group($cellCountry);
        $originCountry = strtoupper($originCountry);
        $candidates = [];
        foreach ($cells as $other) {
            if ($other['contents_bucket'] !== $cell['contents_bucket'] || $other['service_code'] !== $cell['service_code']) {
                continue;
            }
            $otherCountry = $this->regionKey->country($other['region']);
            $otherGroup = $this->regionKey->group($otherCountry);
            if ($otherCountry === $cellCountry) {
                $distance = $this->regionKey->zipDistance($cell['region'], $other['region']);
            } elseif ($cellGroup === $otherGroup) {
                $distance = 1000;
            } elseif ($cellCountry !== $originCountry && $otherCountry !== $originCountry) {
                $distance = 2000;
            } else {
                continue;
            }
            $candidates[] = ['distance' => $distance, 'n' => (int) $other['sample_count'], 'p75' => (float) $other['p75_amount']];
        }
        usort($candidates, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
        $weighted = 0.0;
        $weightSum = 0.0;
        $n = 0;
        foreach ($candidates as $candidate) {
            $w = 1 / (1 + $candidate['distance']);
            $weighted += $candidate['p75'] * $w;
            $weightSum += $w;
            $n += $candidate['n'];
            if ($n >= $minSamples) {
                break;
            }
        }
        return $weightSum > 0 ? round($weighted / $weightSum, 4) : (float) $cell['p75_amount'];
    }
}
