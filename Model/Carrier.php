<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Mulps\ShipStationLiveRates\Model\Client\ShipStationClient;
use Mulps\ShipStationLiveRates\Model\Heuristic\SnapshotRepository;
use Mulps\ShipStationLiveRates\Model\Rate\MarkupApplier;
use Mulps\ShipStationLiveRates\Model\Rate\UspsLookupDetector;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    protected $_code = ModuleConfig::CARRIER_CODE;

    protected $_isFixed = false;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly ModuleConfig $moduleConfig,
        private readonly ShipStationClient $client,
        private readonly SnapshotRepository $heuristics,
        private readonly MarkupApplier $markup,
        private readonly UspsLookupDetector $uspsDetector,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request): Result|bool
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $storeId = $request->getStoreId() ? (int) $request->getStoreId() : null;
        $dest = (string) $request->getDestPostcode();
        $weight = (float) $request->getPackageWeight();
        $result = $this->rateResultFactory->create();

        $live = $this->client->getRates($request, $storeId);
        $appended = 0;
        if ($live !== null && $live['rates'] !== []) {
            $usps = $this->uspsDetector->detect($live['rate_response'], $this->hasUspsCarrierConfigured($storeId));
            $this->_logger->info('Mulps_ShipStationLiveRates USPS lookup: ' . $usps->status . ' ' . $usps->detail);
            foreach ($live['rates'] as $rate) {
                $raw = $this->rawAmount($rate);
                if ($raw === null) {
                    continue;
                }
                $code = (string) ($rate['service_code'] ?? 'live');
                $title = (string) ($rate['service_type'] ?? $rate['service_code'] ?? $this->moduleConfig->getMethodName($storeId));
                $result->append($this->method($code, $title, $this->markup->apply($raw, $dest, $storeId)));
                $appended++;
            }
        }

        if ($appended === 0) {
            $raw = $this->heuristics->estimate($dest, $weight, 'default', $storeId);
            if ($raw === null) {
                return false;
            }
            $title = $this->moduleConfig->getMethodName($storeId);
            if ($this->moduleConfig->showEstimatedTitle($storeId)) {
                $title .= ' (estimated)';
            }
            $result->append($this->method('heuristic', $title, $this->markup->apply($raw, $dest, $storeId)));
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [
            'heuristic' => $this->moduleConfig->getMethodName(),
        ];
    }

    /**
     * @param array<string, mixed> $rate
     */
    private function rawAmount(array $rate): ?float
    {
        $shipping = $rate['shipping_amount']['amount'] ?? null;
        if (!is_numeric($shipping)) {
            return null;
        }
        $extra = 0.0;
        foreach (['confirmation_amount', 'insurance_amount', 'other_amount'] as $key) {
            if (isset($rate[$key]['amount']) && is_numeric($rate[$key]['amount'])) {
                $extra += (float) $rate[$key]['amount'];
            }
        }
        return (float) $shipping + $extra;
    }

    private function method(string $code, string $title, float $price): Method
    {
        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $method->setMethod($code);
        $method->setMethodTitle($title);
        $method->setPrice($price);
        $method->setCost($price);
        return $method;
    }

    private function hasUspsCarrierConfigured(?int $storeId): bool
    {
        return $this->moduleConfig->getCarrierIds($storeId) !== [];
    }
}
