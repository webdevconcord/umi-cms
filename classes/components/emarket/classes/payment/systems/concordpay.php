<?php

use UmiCms\Service;

/**
 * Class concordpayPayment
 */
class concordpayPayment extends payment
{
    /**
     * @var string[]
     */
    protected $keysForResponseSignature = array(
        'merchantAccount',
        'orderReference',
        'amount',
        'currency',
    );

    /**
     * @var string[]
     */
    protected $keysForRequestSignature = array(
        'merchant_id',
        'order_id',
        'amount',
        'currency_iso',
        'description'
    );

    const CURRENCY_HRYVNA = 'UAH';
    const STATUS_APPROVED = 'Approved';
    const STATUS_DECLINED = 'Declined';

    const RESPONSE_TYPE_PAYMENT = 'payment';
    const RESPONSE_TYPE_REVERSE = 'reverse';

    /**
     * Function validate amount order
     * In this case we make off this function
     *
     * {@inheritdoc}
     * @param NULL
     * @return boolean
     */
    public function validate(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     * Returns data for building a form for sending order data to the payment system.
     * Initiates the transformation of the cart into an order.
     *
     * @param $template
     * @return NULL
     * @throws
     */
    public function process($template = null)
    {
        $this->order->order();
        $order  = $this->order;
        $object = $this->object;

        $cmsController = cmsController::getInstance();
        /** @var emarket $emarket */
        $emarket = $cmsController->getModule('emarket');

        $merchant_id = htmlspecialchars_decode($object->getValue('merchant_id'));
        $amount      = number_format($order->getActualPrice(), 2, '.', '');

        $currency = $emarket->getCurrencyFacade()->getCurrent()->getValue('codename');
        if ($currency !== self::CURRENCY_HRYVNA) {
            throw new publicException(getLabel('error-payment-wrong-settings'));
        }

        $customerId    = (int) $order->getCustomerId();
        $customer      = selector::get('object')->id($customerId);
        $customerName  = htmlspecialchars_decode($customer->getValue('fname') . ' ' . $customer->getValue('lname'));
        $customerPhone = htmlspecialchars_decode(customer::get()->getPhone());

        $host = getSelectedServerProtocol() . '://' . $cmsController->getCurrentDomain()->getHost();

        $approve_url  = $host . '/emarket/purchase/result/successful/';
        $decline_url  = $host . '/emarket/purchase/result/fail/';
        $callback_url = $host . '/emarket/gateway/' . $order->getId() . '/index.php/';

        $params = array();
        $params['operation']    = 'Purchase';
        $params['merchant_id']  = $merchant_id;
        $params['amount']       = (float)$amount;
        $params['order_id']     = $order->getId();
        $params['currency_iso'] = $currency;
        $params['description']  = 'Оплата картой на сайте' . ' ' . $host . ', ' . $customerName . ', ' . $customerPhone;
        $params['add_params']   = [];
        $params['approve_url']  = $approve_url;
        $params['decline_url']  = $decline_url;
        $params['cancel_url']   = $decline_url;
        $params['callback_url'] = $callback_url;
        // Statistics.
        $params['client_first_name'] = htmlspecialchars_decode($customer->getValue('fname')) ?? '';
        $params['client_last_name']  = htmlspecialchars_decode($customer->getValue('lname')) ?? '';
        $params['email']             = htmlspecialchars_decode($customer->getValue('email')) ?? '';
        $params['phone']             = $customerPhone ?? '';

        $params['signature'] = $this->getSignature($params, $this->keysForRequestSignature);
        $order->setPaymentStatus('initialized');

        list($templateString) = def_module::loadTemplates("emarket/payment/concordpay/" . $template, "form_block");

        return def_module::parseTemplate($templateString, $params);
    }

    /**
     * @inheritDoc
     * Receives a request from Concordpay and validates the payment parameters.
     * Depending on the result of validation, it sends a request for confirmation or rejection of the payment.
     * Sets the order payment status "Accepted" or "Declined".
     *
     * @throws coreException
     * @throws publicException
     */
    public function poll()
    {
        $buffer = outputBuffer::current();
        $buffer->clear();
        $buffer->contentType("text/plain");

        $rawRequest = Service::Request()->getRawBody();
        $request = json_decode($rawRequest, true);

        if (!is_array($request) || empty($request)) {
            throw new publicException('Empty request');
        }

        if (!isset($request['merchantSignature']) || empty($request['merchantSignature'])) {
            throw new publicException('Missing merchant signature.');
        }

        if ($this->order->getActualPrice() !== (float)$request['amount']) {
            throw new publicException('Wrong order amount');
        }

        $signature = $this->getSignature($request, $this->keysForResponseSignature);
        if ($signature === $request['merchantSignature']) {
            throw new publicException('Wrong signature');
        }

        if ($request['transactionStatus'] === self::STATUS_APPROVED) {
            // Success transaction.
            if ($request['type'] === self::RESPONSE_TYPE_PAYMENT) {
                $this->order->setPaymentStatus('accepted');
            } elseif ($request['type'] === self::RESPONSE_TYPE_REVERSE) {
                $this->order->setPaymentStatus('refund');
            }
            $buffer->push('success');
        } elseif ($request['transactionStatus'] === self::STATUS_DECLINED) {
            // Failed transaction.
            $this->order->setPaymentStatus('declined');
            $buffer->push('fail');
        }

        $buffer->end();
    }

    /**
     * @param $option
     * @param $keys
     * @return string
     */
    public function getSignature($option, $keys)
    {
        $hash = array();
        foreach ($keys as $dataKey) {
            if (!isset($option[$dataKey])) {
                continue;
            }
            if (is_array($option[$dataKey])) {
                foreach ($option[$dataKey] as $v) {
                    $hash[] = $v;
                }
            } else {
                $hash [] = $option[$dataKey];
            }
        }
        $hash = implode(';', $hash);
        $secret_key = htmlspecialchars_decode($this->object->getValue('secret_key'));

        return hash_hmac('md5', $hash, $secret_key);
    }
}
