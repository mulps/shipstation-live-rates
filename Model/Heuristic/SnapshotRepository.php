<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Heuristic;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Mulps\ShipStationLiveRates\Model\ModuleConfig;
use Mulps\ShipStationLiveRates\Model\Rate\ServiceFilter;

class SnapshotRepository
{
    private const TABLE = 'mulps_sslr_heuristic';
    private const CACHE_PREFIX = 'mulps_sslr_heur_';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly ModuleConfig $config,
        private readonly RegionKey $regionKey,
        private readonly ContentsBucket $contentsBucket,
        private readonly ServiceFilter $serviceFilter
    ) {
    }

    public function estimateAnyService(
        string $destPostcode,
        float $weightLb,
        string $destCountry,
        ?int $storeId = null
    ): ?float {
        $originCountry = strtoupper($this->config->getOriginCountry($storeId));
        $target = $this->regionKey->fromAddress($destCountry !== '' ? $destCountry : $originCountry, $destPostcode);
        $targetCountry = $this->regionKey->country($target);
        $targetGroup = $this->regionKey->group($targetCountry);
        $bucket = $this->contentsBucket->fromWeightLb($weightLb);
        $cacheKey = self::CACHE_PREFIX . $target . '_' . $bucket . '_any';
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && is_numeric($cached)) {
            return (float) $cached;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        if (!$connection->isTableExists($table)) {
            return null;
        }

        $select = $connection->select()
            ->from($table, ['region', 'service_code', 'p75_amount', 'merged_p75_amount', 'sample_count'])
            ->where('contents_bucket = ?', $bucket)
            ->order('sample_count DESC');
        $rows = $connection->fetchAll($select);

        $exact = [];
        $country = [];
        $group = [];
        $international = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $amount = (float) $row['merged_p75_amount'] > 0 ? (float) $row['merged_p75_amount'] : (float) $row['p75_amount'];
            if ($amount <= 0) {
                continue;
            }
            $region = (string) ($row['region'] ?? '');
            if ($this->serviceFilter->isExcludedService((string) ($row['service_code'] ?? ''), $storeId)) {
                continue;
            }
            $cellCountry = $this->regionKey->country($region);
            $legacyExact = $cellCountry === 'US' && $region === $this->regionKey->postalPrefix($destPostcode);
            if ($region === $target || $legacyExact) {
                $exact[] = $amount;
                continue;
            }
            if ($cellCountry === $targetCountry) {
                $country[] = $amount;
                continue;
            }
            if ($this->regionKey->group($cellCountry) === $targetGroup && $targetGroup !== $targetCountry) {
                $group[] = $amount;
                continue;
            }
            if ($cellCountry !== $originCountry) {
                $international[] = $amount;
            }
        }

        $pool = $exact !== [] ? $exact : ($country !== [] ? $country : ($group !== [] ? $group : $international));
        if ($pool === []) {
            return null;
        }
        sort($pool);
        $amount = $pool[(int) floor((count($pool) - 1) * 0.75)];
        $this->cache->save((string) $amount, $cacheKey, ['MULPS_SSLR_HEURISTIC'], 86400);
        return $amount;
    }

    /**
     * @param list<array{region:string,contents_bucket:string,service_code:string,sample_count:int,median_amount:float,p75_amount:float,merged_p75_amount:float}> $cells
     */
    public function replaceAll(array $cells): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->beginTransaction();
        try {
            $connection->delete($table);
            foreach ($cells as $cell) {
                $connection->insert($table, $cell);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $this->cache->clean(['MULPS_SSLR_HEURISTIC']);
    }
}
