<?php
namespace Conekta\Payments\Model\Ui\CreditCard;

use Conekta\Payments\Helper\Data as ConektaHelper;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Model\CcConfig;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Seis10\ContadoMSI\Helper\Data as MSIHelper;
use Conekta\Payments\Logger\Logger as ConektaLogger;

class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'conekta_cc';

    protected $_assetRepository;

    protected $_ccCongig;

    protected $_conektaHelper;

    protected $_checkoutSession;

    protected $_conektaLogger;

    protected $_msiHelper;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    protected $_priceCurrency;

    public function __construct(
        Repository $assetRepository,
        CcConfig $ccCongig,
        ConektaHelper $conektaHelper,
        Session $checkoutSession,
        PriceCurrencyInterface $priceCurrency,
        MSIHelper $msiHelper,
        ConektaLogger $conektaLogger
    ) {
        $this->_assetRepository = $assetRepository;
        $this->_ccCongig = $ccCongig;
        $this->_conektaHelper = $conektaHelper;
        $this->_checkoutSession = $checkoutSession;
        $this->_priceCurrency   = $priceCurrency;
        $this->_msiHelper       = $msiHelper;

        $this->_conektaLogger = $conektaLogger;
        $this->_conektaLogger->info('Conekta\Payments\Model\Ui\CreditCard\ConfigProvider:: __construct');
    }

    public function getConfig()
    {
        return [
            'payment' => [
                self::CODE => [
                    'availableTypes' => $this->getCcAvalaibleTypes(),
                    'months' => $this->_getMonths(),
                    'years' => $this->_getYears(),
                    'hasVerification' => true,
                    'cvvImageUrl' => $this->getCvvImageUrl(),
                    'monthly_installments' => $this->getMonthlyInstallments(),
                    'active_monthly_installments' => $this->getActiveMonthlyInstallments(),
                    'minimum_amount_monthly_installments' => $this->getMinimumAmountMonthlyInstallments(),
                    'total' => $this->getQuote()->getGrandTotal(),
                    'formattotal' => $this->customGetFormtatedPrice(),
                    'formatInstalls' => $this->customGetFormtatedPriceInstallements()
                ]
            ]
        ];
    }

    /**
    * TEST NEW CUSTOM MODULES
    */
    public function customGetFormtatedPrice(){
        //$this->_conektaLogger->info('Conekta\Payments\Model\Ui\CreditCard\ConfigProvider::customGetFormtatedPrice');

        $total = $this->getQuote()->getGrandTotal();
        $format = $this->_priceCurrency->format($total,false,2);
        
        //$this->_conektaLogger->info($format);
        return $format;
    }


    public function customGetFormtatedPriceInstallements(){
        $this->_conektaLogger->info('Conekta\Payments\Model\Ui\CreditCard\ConfigProvider::customGetFormtatedPriceInstallements');

        $total  = $this->getQuote()->getGrandTotal();
        $months = $this->getMonthlyInstallments();

        $data   = $this->_msiHelper->getMSIOrder($total);

        return $data;
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
        $this->_conektaLogger->info('Conekta\Payments\Model\Ui\CreditCard\ConfigProvider:: __getMonthlyInstallments');
        
        $total = $this->getQuote()->getGrandTotal();
        $this->_conektaLogger->info('TOTAL '.$total);

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
        
        $this->_conektaLogger->info('months: ');
        $this->_conektaLogger->info(json_encode($months));

        return $months;
    }

    public function getMinimumAmountMonthlyInstallments()
    {
        return $this->_conektaHelper->getConfigData('conekta_cc', 'minimum_amount_monthly_installments');
    }

    public function getActiveMonthlyInstallments()
    {
      $active_monthly_installments = $this->_conektaHelper->getConfigData('conekta/conekta_creditcard', 'active_monthly_installments');
      if ($active_monthly_installments == "0")
      {
        return false;
      }
      else
      {
        return true;
      }
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
