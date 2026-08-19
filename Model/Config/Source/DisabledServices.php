<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Mulps\ShipStationLiveRates\Model\Client\ShipStationClient;
use Mulps\ShipStationLiveRates\Model\ModuleConfig;

class DisabledServices implements OptionSourceInterface
{
    public function __construct(
        private readonly ShipStationClient $client,
        private readonly ModuleConfig $config
    ) {
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        $seen = [];
        try {
            foreach ($this->client->listServiceCatalog() as $row) {
                $code = $row['code'];
                $options[] = ['value' => $code, 'label' => $row['label']];
                $seen[$code] = true;
            }
        } catch (\Throwable) {
            // Admin must still load if ShipStation is down.
        }
        foreach ($this->config->getDisabledServiceCodes() as $code) {
            if (!isset($seen[$code])) {
                $options[] = ['value' => $code, 'label' => $code . ' (saved, not in current ShipStation list)'];
                $seen[$code] = true;
            }
        }
        return $options;
    }
}
