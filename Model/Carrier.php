<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Model;

use Magento\Framework\DataObject;
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
        $domestic = $this->moduleConfig->isDomesticDestination($this->destCountryId($request), $storeId);
        $weight = $this->moduleConfig->billedWeightLb((float) $request->getPackageWeight(), $storeId);
        $result = $this->rateResultFactory->create();
        $appended = 0;

        try {
            $live = $this->client->getRates($request, $storeId);
            if ($live !== null && $live['rates'] !== []) {
                $usps = $this->uspsDetector->detect($live['rate_response'], true);
                $this->_logger->info('Mulps_ShipStationLiveRates USPS lookup: ' . $usps->status . ' ' . $usps->detail);
                foreach ($this->cheapestPerService($live['rates']) as $rate) {
                    $raw = $this->rawAmount($rate);
                    if ($raw === null) {
                        continue;
                    }
                    $code = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) ($rate['service_code'] ?? 'live'))) ?? 'live';
                    $title = (string) ($rate['service_type'] ?? $rate['service_code'] ?? $this->moduleConfig->getMethodName($storeId));
                    $result->append($this->method($code, $title, $this->markup->apply($raw, $dest, $storeId, $domestic)));
                    $appended++;
                }
            }
        } catch (\Throwable $e) {
            $this->_logger->error('Mulps_ShipStationLiveRates collectRates: ' . $e->getMessage());
        }

        if ($appended === 0) {
            $raw = $this->heuristics->estimateAnyService($dest, $weight, $this->destCountryId($request), $storeId);
            if ($domestic) {
                $raw ??= $this->client->lastGoodAmount($dest);
            }
            $title = $this->moduleConfig->getGuaranteedTitle($storeId);
            if ($raw === null) {
                $price = $this->moduleConfig->getGuaranteedPrice($domestic, $storeId);
                $result->append($this->method($domestic ? 'backup' : 'backup_intl', $title, $price));
            } else {
                if ($this->moduleConfig->showEstimatedTitle($storeId)) {
                    $title .= ' (estimated)';
                }
                $result->append($this->method('backup', $title, $this->markup->apply($raw, $dest, $storeId, $domestic)));
            }
        }

        return $result;
    }

    /**
     * Magento may skip the carrier on empty country or extra validation. Keep collecting.
     *
     * @param RateRequest $request
     * @return $this|bool|\Magento\Quote\Model\Quote\Address\RateResult\Error
     */
    public function checkAvailableShipCountries(DataObject $request)
    {
        if (!$request->getDestCountryId()) {
            return $this;
        }
        return parent::checkAvailableShipCountries($request);
    }

    /**
     * @param RateRequest $request
     * @return $this|bool|\Magento\Quote\Model\Quote\Address\RateResult\Error
     */
    public function proccessAdditionalValidation(DataObject $request)
    {
        return $this;
    }

    private function destCountryId(RateRequest $request): string
    {
        $candidates = [
            (string) $request->getDestCountryId(),
            (string) $request->getData('dest_country_id'),
        ];
        foreach ($candidates as $value) {
            $value = strtoupper(trim($value));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [
            'backup' => $this->moduleConfig->getGuaranteedTitle(),
            'backup_intl' => $this->moduleConfig->getGuaranteedTitle(),
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

    /**
     * @param list<array<string, mixed>> $rates
     * @return list<array<string, mixed>>
     */
    private function cheapestPerService(array $rates): array
    {
        $best = [];
        foreach ($rates as $rate) {
            $code = (string) ($rate['service_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $amount = $this->rawAmount($rate);
            if ($amount === null) {
                continue;
            }
            $current = $best[$code]['amount'] ?? null;
            if ($current === null || $amount < $current) {
                $best[$code] = ['amount' => $amount, 'rate' => $rate];
            }
        }
        return array_values(array_map(static fn (array $row): array => $row['rate'], $best));
    }
}
