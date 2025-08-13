<?php

use Payfast\PayfastCommon\Aggregator\Request\PaymentRequest;

class PayfastITN {
    private $paymentRequest;
    private $data;
    private $pfParamString;

    public function __construct() {
        $this->paymentRequest = new PaymentRequest(true);
        $this->data = $this->paymentRequest->pfGetData();
        $this->pfParamString = '';
    }

    public function getData() {
        return $this->data;
    }

    public function isSignatureValid($passphrase) {
        return $this->paymentRequest->pfValidSignature($this->data, $this->pfParamString, $passphrase);
    }

    public function isDataValid($moduleInfo, $host) {
        return $this->paymentRequest->pfValidData($moduleInfo, $host, $this->pfParamString);
    }

    public function amountsEqual($amount1, $amount2) {
        return $this->paymentRequest->pfAmountsEqual($amount1, $amount2);
    }

    public function getPaymentRequest() {
        return $this->paymentRequest;
    }

    // Expose constants from PaymentRequest for error handling
    const PF_ERR_BAD_ACCESS = 'An invalid request was sent to the server';
    const PF_ERR_INVALID_SIGNATURE = 'Invalid signature';
    const PF_ERR_NO_SESSION = 'No saved session found';
    const PF_ERR_AMOUNT_MISMATCH = 'Amount mismatch';
}
