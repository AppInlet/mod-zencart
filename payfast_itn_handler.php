<?php

/**
 * payfast_itn_handler
 *
 * Callback handler for Payfast ITN
 *
 * Copyright (c) 2025 Payfast (Pty) Ltd
 * You (being anyone who is not Payfast (Pty) Ltd) may download and use this plugin / code in your own
 * website in conjunction with a registered and active Payfast account. If your Payfast account is terminated for any
 * reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code
 * or part thereof in any way.
 */

$show_all_errors   = false;
$current_page_base = 'payfastitn';
$loaderPrefix      = 'payfast_itn';

require_once 'includes/configure.php';
require_once 'includes/defined_paths.php';
require_once 'includes/modules/payment/payfast/payfast_functions.php';
require_once 'includes/application_top.php';

if (!defined('TOPMOST_CATEGORY_PARENT_ID')) {
    define('TOPMOST_CATEGORY_PARENT_ID', 0);
}

require_once DIR_WS_CLASSES . 'payment.php';
require_once 'includes/modules/payment/payfast/vendor/autoload.php';
require_once 'includes/classes/PayfastConfig.php';
require_once 'includes/classes/PayfastLogger.php';
require_once 'includes/classes/PayfastITN.php';
require_once 'includes/classes/ZenCartOrderManager.php';

if (!defined('PF_SOFTWARE_NAME')) define('PF_SOFTWARE_NAME', 'ZenCart');
if (!defined('PF_SOFTWARE_VER')) define('PF_SOFTWARE_VER', PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR);
if (!defined('PF_MODULE_NAME')) define('PF_MODULE_NAME', 'Payfast_ZenCart');
if (!defined('PF_MODULE_VER')) define('PF_MODULE_VER', '1.4.0');
if (!defined('MODULE_PAYMENT_PF_SERVER_LIVE')) define('MODULE_PAYMENT_PF_SERVER_LIVE', 'payfast.co.za');
if (!defined('MODULE_PAYMENT_PF_SERVER_TEST')) define('MODULE_PAYMENT_PF_SERVER_TEST', 'sandbox.payfast.co.za');
if (!defined('PF_DEBUG')) define('PF_DEBUG', true);

class PayfastITNHandler {
    private $config;
    private $logger;
    private $itn;
    private $orderManager;

    public function __construct($db) {
        $this->config = new PayfastConfig();
        $this->itn = new PayfastITN();
        $this->logger = new PayfastLogger($this->itn->getPaymentRequest());
        $this->orderManager = new ZenCartOrderManager($db);
    }

    public function handleRequest() {
        try {
            $this->logger->log('Payfast ITN call received');
            header('HTTP/1.0 200 OK');
            flush();

            $data = $this->itn->getData();
            if ($data === false) {
                throw new Exception(PayfastITN::PF_ERR_BAD_ACCESS);
            }
            $this->logger->log('Payfast Data: ' . json_encode($data));

            if (!$this->itn->isSignatureValid($this->config->getPassphrase())) {
                throw new Exception(PayfastITN::PF_ERR_INVALID_SIGNATURE);
            }
            if (!$this->itn->isDataValid($this->config->getModuleInfo(), $this->config->getServer())) {
                throw new Exception(PayfastITN::PF_ERR_BAD_ACCESS);
            }

            $this->processTransaction($data);
        } catch (Exception $e) {
            $this->handleError($e->getMessage(), $data ?? []);
        } finally {
            $this->logger->close();
        }
    }

    private function processTransaction($data) {
        global $zco_notifier;
        $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_BEGIN');
        list($pfOrderId, $zcOrderId, $txnType) = $this->orderManager->lookupTransaction($data);
        $this->logger->log(
            'Transaction details:' .
            "\n- pfOrderId = " . ($pfOrderId ?? 'null') .
            "\n- zcOrderId = " . ($zcOrderId ?? 'null') .
            "\n- txnType   = " . ($txnType ?? 'null')
        );
        $ts = time();

        $this->logger->log('Processing transaction');

        switch ($txnType) {
            case 'new':
                $this->handleNewTransaction($data, $ts);
                break;
            case 'cleared':
                $this->handleClearedTransaction($data, $pfOrderId, $zcOrderId, $ts);
                break;
            case 'update':
                $this->handleUpdateTransaction($data, $pfOrderId, $ts);
                break;
            case 'failed':
                $this->handleFailedTransaction($data, $pfOrderId, $zcOrderId, $ts);
                break;
            default:
                $this->logger->log("Unknown transaction type: $txnType");
                break;
        }

        if ($txnType !== 'new' && isset($newStatus)) {
            $this->orderManager->updateOrderStatusAndHistory($data, $zcOrderId, $txnType, $ts, $newStatus);
        }
    }

    private function handleNewTransaction($data, $ts) {
        global $order, $zco_notifier, $order_total_modules, $order_totals;
        list($zcSessName, $zcSessID) = explode('=', $data['custom_str2']);
        $this->logger->log('Session Name = ' . $zcSessName . ', Session ID = ' . $zcSessID);
        $session = $this->orderManager->retrieveSession($zcSessID);
        $this->logger->log('Session contents: ' . print_r($session, true));
        $this->orderManager->createOrderEnvironment($session);

        // Ensure customer_id is set
        if (!isset($session['customer_id'])) {
            $this->logger->log('Warning: customer_id missing in session, attempting to set from data');
            if (isset($data['custom_int1'])) {
                $_SESSION['customer_id'] = $data['custom_int1'];
            } else {
                $this->logger->log('Error: No customer_id available');
                throw new Exception('Missing customer_id in session or Payfast data');
            }
        }
        $this->logger->log('Customer ID: ' . ($_SESSION['customer_id'] ?? 'Not set'));

        // Initialize Customer object
        $customer = new Customer($_SESSION['customer_id']);
        $this->logger->log('Customer initialized: ' . (is_object($customer) ? 'Success' : 'Failed'));

        if (!isset($session['cart']) || !is_object($session['cart'])) {
            $this->logger->log('Warning: Cart missing in session, initializing empty cart');
            $session['cart'] = new shoppingCart();
            $_SESSION['cart'] = $session['cart'];
        }
        $this->logger->log('Cart contents: ' . print_r($_SESSION['cart'], true));

        if (!$this->orderManager->checkOrderData($data)) {
            throw new Exception(PayfastITN::PF_ERR_AMOUNT_MISMATCH);
        }

        $order = new order();
        $this->logger->log('Order info: ' . print_r($order->info, true));
        if (is_null($order)) {
            $this->logger->log('Error: $order is null after initialization');
            throw new Exception('Failed to initialize order object');
        }

        $order_total_modules = new order_total();
        $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_BEFORE_ORDER_TOTALS_PROCESS');
        $this->logger->log('Calling order_total::process()');
        $order_totals = $order_total_modules->process();
        $this->logger->log('Returned from order_total::process(): ' . print_r($order_totals, true));
        $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_TOTALS_PROCESS');

        $this->logger->log('Global $order_total_modules: ' . (is_object($order_total_modules) ? 'Set' : 'Null'));
        $this->logger->log('Global $order_totals: ' . (is_array($order_totals) ? print_r($order_totals, true) : 'Null'));

        $zcOrderId = $this->orderManager->createOrder($order, $order_totals);
        $pfOrderId = $this->orderManager->createPayfastOrder($data, $zcOrderId, $ts);
        $this->orderManager->createPayfastHistory($data, $pfOrderId, $ts);

        $newStatus = ($data['payment_status'] === 'PENDING') ?
            MODULE_PAYMENT_PF_PROCESSING_STATUS_ID : MODULE_PAYMENT_PF_ORDER_STATUS_ID;
        $this->orderManager->updateOrderStatus($zcOrderId, $newStatus, 'Payfast status: ' . $data['payment_status'], $ts);

        $this->orderManager->addProductsToOrder($order, $zcOrderId);
        $this->orderManager->deleteSession($zcSessID);
        $this->logger->log('Payfast ITN Complete');

    }

    private function handleClearedTransaction($data, $pfOrderId, $zcOrderId, $ts) {
        $this->orderManager->createPayfastHistory($data, $pfOrderId, $ts);
        $newStatus = MODULE_PAYMENT_PF_ORDER_STATUS_ID;
        $this->orderManager->updateOrderStatus($zcOrderId, $newStatus, 'Payfast status: ' . $data['payment_status'], $ts);
    }

    private function handleUpdateTransaction($data, $pfOrderId, $ts) {
        $this->orderManager->createPayfastHistory($data, $pfOrderId, $ts);
    }

    private function handleFailedTransaction($data, $pfOrderId, $zcOrderId, $ts) {
        $this->orderManager->createPayfastHistory($data, $pfOrderId, $ts);
        $newStatus = MODULE_PAYMENT_PF_PREPARE_ORDER_STATUS_ID;
        $this->orderManager->updateOrderStatus($zcOrderId, $newStatus, 'Payment failed (Payfast id = ' . $data['pf_payment_id'] . ')', $ts);

        $this->sendErrorEmail(
            'Payfast ITN Transaction on your site',
            "A failed Payfast transaction on your website requires attention\n" .
            "------------------------------------------------------------\n" .
            "Site: " . STORE_NAME . ' (' . HTTP_SERVER . DIR_WS_CATALOG . ")\n" .
            "Order ID: $zcOrderId\n" .
            "Payfast Transaction ID: " . $data['pf_payment_id'] . "\n" .
            "Payfast Payment Status: " . $data['payment_status'] . "\n" .
            "Order Status Code: $newStatus"
        );
    }

    private function handleError($errorMessage, $data) {
        $this->logger->log("Error: $errorMessage");
        header('HTTP/1.1 500 Internal Server Error');
        flush();

        $body = "An invalid Payfast transaction on your website requires attention\n" .
            "------------------------------------------------------------\n" .
            "Site: " . STORE_NAME . ' (' . HTTP_SERVER . DIR_WS_CATALOG . ")\n" .
            "Remote IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
            "Remote host name: " . gethostbyaddr($_SERVER['REMOTE_ADDR']) . "\n" .
            (isset($data['pf_payment_id']) ? "Payfast Transaction ID: " . $data['pf_payment_id'] . "\n" : '') .
            (isset($data['payment_status']) ? "Payfast Payment Status: " . $data['payment_status'] . "\n" : '') .
            "Error: $errorMessage";
        if ($errorMessage === PayfastITN::PF_ERR_AMOUNT_MISMATCH) {
            $body .= "\nValue received: " . $data['amount_gross'] . "\nValue should be: " . $_SESSION['payfast_amount'];
        }
        $this->sendErrorEmail("Payfast ITN error: $errorMessage", $body);
    }

    private function sendErrorEmail($subject, $body) {
        zen_mail(
            STORE_OWNER,
            $this->config->getDebugEmail(),
            $subject,
            "Hi,\n\n" . $body . "\n------------------------------------------------------------\n",
            STORE_OWNER,
            STORE_OWNER_EMAIL_ADDRESS,
            null,
            'debug'
        );
    }
}

$handler = new PayfastITNHandler($db);
$handler->handleRequest();
