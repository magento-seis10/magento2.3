<?php
namespace Conekta\Payments\Gateway\Request\CreditCard;

use Conekta\Payments\Helper\Data as ConektaHelper;
use Conekta\Payments\Logger\Logger as ConektaLogger;
use Seis10\ContadoMSI\Helper\Data as MSIHelper;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

class CaptureRequest implements BuilderInterface
{
    private $config;

    private $subjectReader;

    protected $_conektaHelper;

    private $_conektaLogger;

    protected $_msiHelper;

    public function __construct(
        ConfigInterface $config,
        SubjectReader $subjectReader,
        MSIHelper $msiHelper,
        ConektaHelper $conektaHelper,
        ConektaLogger $conektaLogger
    ) {
        $this->_conektaHelper = $conektaHelper;
        $this->_conektaLogger = $conektaLogger;
        $this->_msiHelper     = $msiHelper;
        $this->_conektaLogger->info('Request CaptureRequest :: __construct');

        $this->config = $config;
        $this->subjectReader = $subjectReader;
    }

    public function build(array $buildSubject)
    {
        $this->_conektaLogger->info('CUSTOM Request CaptureRequest :: build');

        $paymentDO  = $this->subjectReader->readPayment($buildSubject);
        $payment    = $paymentDO->getPayment();
        $order      = $paymentDO->getOrder();

        $token          = $payment->getAdditionalInformation('card_token');
        $installments   = $payment->getAdditionalInformation('monthly_installments');

        $amount     = (int)($order->getGrandTotalAmount() * 100);

        $request    = [];
        
        try {

            $request['payment_method_details'] = $this->getChargeCard(
                $amount,
                $token
            );
            
            $request['metadata'] = [
                'order_id'       => $order->getOrderIncrementId(),
                'soft_validations'  => 'true'
            ];
            
            if ($this->_validateMonthlyInstallments($amount, $installments)) {
                
                //Siginifica que voy a modificar el valor total de la orden
                $new_amount = $this->_getFinalPriceWithInstallments($amount, $installments);

                if($new_amount){

                    $this->_conektaLogger->info('CUSTOM new amount: '.$new_amount);
                    $amount = $new_amount;

                    $request['payment_method_details'] = $this->getChargeCard(
                        $amount,
                        $token
                    );

                    $request['metadata']['grand_total'] = $amount;

                }

                //Significa que pasó la validación de tener los meses activados y el monto fue mayor
                $request['payment_method_details']['payment_method']['monthly_installments'] = $installments;
                
            }
        } catch (\Exception $e) {
            $this->_conektaLogger->info('Request CaptureRequest :: build Problem');
            $this->_conektaLogger->info($e->getMessage());
            throw new \Magento\Framework\Validator\Exception(__('Problem Creating Charge'));
        }

        $request['CURRENCY'] = $order->getCurrencyCode();
        $request['TXN_TYPE'] = 'A';
        $request['INVOICE'] = $order->getOrderIncrementId();
        //$request['AMOUNT'] = number_format($order->getGrandTotalAmount(), 2);
        $request['AMOUNT'] = number_format($amount, 2);

        $this->_conektaLogger->info('Request CaptureRequest :: build : return request', $request);

        return $request;
    }

    public function getChargeCard($amount, $tokenId)
    {
        $charge = [
            'payment_method' => [
                'type'     => 'card',
                'token_id' => $tokenId
            ],
            'amount' => $amount
        ];

        return $charge;
    }

    private function _validateMonthlyInstallments($amount, $installments)
    {

        $this->_conektaLogger->info('CUSTOM _validateMonthlyInstallments');

        $active_monthly_installments = $this->_conektaHelper->getConfigData(
            'conekta/conekta_creditcard',
            'active_monthly_installments'
        );
        if ($active_monthly_installments) {
            $minimum_amount_monthly_installments = $this->_conektaHelper->getConfigData(
                'conekta/conekta_creditcard',
                'minimum_amount_monthly_installments'
            );
            if ($amount >= ($minimum_amount_monthly_installments * 100) && $installments > 1) {
                return true;
            }
        }

        return false;
    }

    private function _getFinalPriceWithInstallments($amount, $installments){

        $this->_conektaLogger->info('CUSTOM __getFinalPriceWithInstallments');

        $new_amount =  $this->_msiHelper->getFinalPriceMSIOrder($amount, $installments);
        
        $this->_conektaLogger->info('CUSTOM new_amount: '.$new_amount);
        return $new_amount;
    }
}
