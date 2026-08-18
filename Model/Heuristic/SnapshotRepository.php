<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Heuristic;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Mulps\ShipStationLiveRates\Model\ModuleConfig;
use Mulps\ShipStationLiveRates\Model\Rate\MarkupApplier;

class SnapshotRepository
{
    private const TABLE = 'mulps_sslr_heuristic';
    private const CACHE_PREFIX = 'mulps_sslr_heur_';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly ModuleConfig $config,
        private readonly MarkupApplier $markup,
        private readonly ContentsBucket $contentsBucket
    ) {
    }

    public function estimate(string $destPostcode, float $weightLb, string $serviceCode, ?int $storeId = null): ?float
    {
        $region = $this->markup->zip3($destPostcode);
        $bucket = $this->contentsBucket->fromWeightLb($weightLb);
        $min = $this->config->getHeuristicMinSamples($storeId);
        $cacheKey = self::CACHE_PREFIX . $region . '_' . $bucket . '_' . $serviceCode;
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
            ->from($table)
            ->where('region = ?', $region)
            ->where('contents_bucket = ?', $bucket)
            ->where('service_code = ?', $serviceCode)
            ->limit(1);
        $row = $connection->fetchRow($select);
        $amount = null;
        if (is_array($row) && (int) $row['sample_count'] >= $min) {
            $amount = (float) $row['p75_amount'];
        } elseif (is_array($row) && (float) $row['merged_p75_amount'] > 0) {
            $amount = (float) $row['merged_p75_amount'];
        } else {
            $select = $connection->select()
                ->from($table)
                ->where('contents_bucket = ?', $bucket)
                ->where('service_code = ?', $serviceCode)
                ->order('sample_count DESC')
                ->limit(1);
            $fallback = $connection->fetchRow($select);
            if (is_array($fallback) && (float) $fallback['merged_p75_amount'] > 0) {
                $amount = (float) $fallback['merged_p75_amount'];
            } elseif (is_array($fallback) && (float) $fallback['p75_amount'] > 0) {
                $amount = (float) $fallback['p75_amount'];
            }
        }

        if ($amount === null) {
            return null;
        }

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
