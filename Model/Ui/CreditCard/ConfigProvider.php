<?php
namespace Conekta\Payments\Model\Ui\CreditCard;

use Conekta\Payments\Helper\Data as ConektaHelper;
use Conekta\Payments\Logger\Logger as ConektaLogger;
use Conekta\Payments\Model\Config;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Model\CcConfig;

/**
 * Class ConfigProvider
 * @package Conekta\Payments\Model\Ui\CreditCard
 */
class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'conekta_cc';

    protected $_assetRepository;

    protected $_ccCongig;

    protected $_conektaHelper;

    protected $_checkoutSession;
    /**
     * @var CustomerSession
     */
    protected $customerSession;
    /**
     * @var Config
     */
    protected $config;
    /**
     * @var ConektaLogger
     */
    protected $conektaLogger;

    /**
     * ConfigProvider constructor.
     * @param Repository $assetRepository
     * @param CcConfig $ccCongig
     * @param ConektaHelper $conektaHelper
     * @param Session $checkoutSession
     * @param CustomerSession $customerSession
     * @param Config $config
     */
    public function __construct(
        Repository $assetRepository,
        CcConfig $ccCongig,
        ConektaHelper $conektaHelper,
        Session $checkoutSession,
        CustomerSession $customerSession,
        Config $config,
        ConektaLogger $conektaLogger
    ) {
        $this->_assetRepository = $assetRepository;
        $this->_ccCongig = $ccCongig;
        $this->_conektaHelper = $conektaHelper;
        $this->_checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->config = $config;
        $this->conektaLogger = $conektaLogger;
    }

    public function getConfig()
    {
        $savedCardEnable = $this->getEnableSaveCardConfig() ? true : false;
        return [
            'payment' => [
                self::CODE => [
                    'availableTypes' => $this->getCcAvalaibleTypes(),
                    'months' => $this->_getMonths(),
                    'years' => $this->_getYears(),
                    'hasVerification' => true,
                    'cvvImageUrl' => $this->getCvvImageUrl(),
                    'monthly_installments' => $this->getMonthlyInstallments(),
                    'active_monthly_installments' => $this->getMonthlyInstallments(),
                    'minimum_amount_monthly_installments' => $this->getMinimumAmountMonthlyInstallments(),
                    'total' => $this->getQuote()->getGrandTotal(),
                    'enable_saved_card' => $savedCardEnable,
                    'saved_card' => $savedCardEnable ? $this->getSavedCard() : []
                ]
            ]
        ];
    }

    /**
     * @return mixed
     */
    public function getEnableSaveCardConfig()
    {
        return $this->_conektaHelper->getConfigData('conekta/conekta_global', 'enable_saved_card');
    }

    /**
     * @return array
     */
    public function getSavedCard()
    {
        $result = [];
        if ($this->customerSession->isLoggedIn()) {
            $this->config->initializeConektaLibrary();
            $customer = $this->customerSession->getCustomer();

            if ($customer->getConektaCustomerId()) {
                try {
                    $customerApi = \Conekta\Customer::find($customer->getConektaCustomerId());
                    $response = (array) $customerApi->payment_sources;
                    foreach ($response as $payment) {
                        $result[$payment['id']] = $payment['name'] . ' XXXX-' . $payment['last4'] . ' ' . $payment['brand'];
                    }
                } catch (\Conekta\ProccessingError $error) {
                    $this->conektaLogger->info($error->getMessage());
                } catch (\Conekta\ParameterValidationError $error) {
                    $this->conektaLogger->info($error->getMessage());
                } catch (\Conekta\Handler $error) {
                    $this->conektaLogger->info($error->getMessage());
                }
            }
        }
        return $result;
    }

    public function getCcAvalaibleTypes()
    {
        $result = [];
        $cardTypes = $this->_ccCongig->getCcAvailableTypes();
        $cc_types = explode(',', $this->_conektaHelper->getConfigData('conekta_cc', 'cctypes'));
        if (!empty($cc_types)) {
            foreach ($cc_types as $key) {
                if (isset($cardTypes[$key])) {
                    $result[$key] = $cardTypes[$key];
                }
            }
        }
        return $result;
    }

    public function getMonthlyInstallments()
    {
        $total = $this->getQuote()->getGrandTotal();
        $months = [1];
        if ((int)$this->getMinimumAmountMonthlyInstallments() < (int)$total) {
            $months = explode(',', $this->_conektaHelper->getConfigData('conekta_cc', 'monthly_installments'));
            if (!in_array("1", $months)) {
                array_push($months, "1");
            }
            asort($months);
            foreach ($months as $k => $v) {
                if ((int)$total < ($v * 100)) {
                    unset($months[$k]);
                }
            }
        }
        return $months;
    }

    public function getMinimumAmountMonthlyInstallments()
    {
        return $this->_conektaHelper->getConfigData('conekta_cc', 'minimum_amount_monthly_installments');
    }

    public function getCvvImageUrl()
    {
        return $this->_assetRepository->getUrl('Conekta_Payments::images/cvv.png');
    }

    private function _getMonths()
    {
        return [
            "1" => "01 - Enero",
            "2" => "02 - Febrero",
            "3" => "03 - Marzo",
            "4" => "04 - Abril",
            "5" => "05 - Mayo",
            "6" => "06 - Junio",
            "7" => "07 - Julio",
            "8" => "08 - Augosto",
            "9" => "09 - Septiembre",
            "10" => "10 - Octubre",
            "11" => "11 - Noviembre",
            "12" => "12 - Diciembre"
        ];
    }

    private function _getYears()
    {
        $years = [];
        $cYear = (integer) date("Y");
        $cYear = --$cYear;
        for ($i=1; $i <= 8; $i++) {
            $year = (string) ($cYear + $i);
            $years[$year] = $year;
        }

        return $years;
    }

    private function _getStartYears()
    {
        $years = [];
        $cYear = (integer) date("Y");

        for ($i=5; $i>=0; $i--) {
            $year = (string)($cYear - $i);
            $years[$year] = $year;
        }

        return $years;
    }

    public function getQuote()
    {
        return $this->_checkoutSession->getQuote();
    }
}
