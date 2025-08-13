<?php

use Payfast\PayfastCommon\Aggregator\Request\PaymentRequest;

class PayfastLogger {
    private $paymentRequest;

    public function __construct(PaymentRequest $paymentRequest) {
        $this->paymentRequest = $paymentRequest;
    }

    public function log($message) {
        $this->paymentRequest->pflog($message);
    }

    public function close() {
        $this->paymentRequest->pflog('');
    }
}
