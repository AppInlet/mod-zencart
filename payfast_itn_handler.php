<?php

/**
 * payfast_itn_handler
 *
 * Callback handler for Payfast ITN
 *
 * Copyright (c) 2024 Payfast (Pty) Ltd
 * You (being anyone who is not Payfast (Pty) Ltd) may download and use this plugin / code in your own
 * website in conjunction with a registered and active Payfast account. If your Payfast account is terminated for any
 * reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code
 * or part thereof in any way.
 */

//// bof: Load ZenCart configuration
$show_all_errors   = false;
$current_page_base = 'payfastitn';
$loaderPrefix      = 'payfast_itn';

require_once 'includes/configure.php';
require_once 'includes/defined_paths.php';
require_once 'includes/modules/payment/payfast/payfast_functions.php';
require_once 'includes/application_top.php';
require_once DIR_WS_CLASSES . 'payment.php';
require_once 'includes/modules/payment/payfast/vendor/autoload.php';

use Payfast\PayfastCommon\PayfastCommon;

$zcSessName = '';
$zcSessID   = '';
//// eof: Load ZenCart configuration

$show_all_errors    = true;
$logdir             = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : 'includes/modules/payment/payfast';
$debug_logfile_path = $logdir . '/itn_debug_php_errors-' . time() . '.log';
ini_set('log_errors', 1);
ini_set('log_errors_max_len', 0);
ini_set('display_errors', 0); // do not output errors to screen/browser/client (only to log file)
ini_set('error_log', DIR_FS_CATALOG . $debug_logfile_path);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);


// Variable Initialization
const LINE_LITERAL                       = ' line ';
const REGEX_HI_LITERAL                   = "Hi,\n\n";
const LONG_LINE_LITERAL                  = "------------------------------------------------------------\n";
const SITE_LITERAL                       = 'Site: ';
const ORDER_LITERAL                      = 'Order ID: ';
const TRANSACTION_LITERAL                = 'Payfast Transaction ID: ';
const PAYMENT_LITERAL                    = 'Payfast Payment Status: ';

const PF_SOFTWARE_NAME = 'ZenCart';
if (defined('PROJECT_VERSION_MAJOR')) {
    define('PF_SOFTWARE_VER', PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR);
}
const PF_MODULE_NAME = 'Payfast_ZenCart';
const PF_MODULE_VER  = '1.2.0';

if (!defined('MODULE_PAYMENT_PF_SERVER_LIVE')) {
    define('MODULE_PAYMENT_PF_SERVER_LIVE', 'payfast.co.za');
}

if (!defined('MODULE_PAYMENT_PF_SERVER_TEST')) {
    define('MODULE_PAYMENT_PF_SERVER_TEST', 'sandbox.payfast.co.za');
}

$moduleInfo = [
    'pfSoftwareName' => PF_SOFTWARE_NAME,
    'pfSoftwareVer' => PF_SOFTWARE_VER,
    'pfSoftwareModuleName' => PF_MODULE_NAME,
    'pfModuleVer' => PF_MODULE_VER,
];
$pfError       = false;
$pfErrMsg      = '';
$pfData        = [];
$pfHost        = (strcasecmp(MODULE_PAYMENT_PF_SERVER, 'live') == 0) ?
    MODULE_PAYMENT_PF_SERVER_LIVE : MODULE_PAYMENT_PF_SERVER_TEST;
$pfOrderId     = '';
$pfParamString = '';
$pfPassphrase  = MODULE_PAYMENT_PF_PASSPHRASE;
$pfDebugEmail  = defined('MODULE_PAYMENT_PF_DEBUG_EMAIL_ADDRESS')
    ? MODULE_PAYMENT_PF_DEBUG_EMAIL_ADDRESS : STORE_OWNER_EMAIL_ADDRESS;

if (!defined('PF_DEBUG')) {
    // phpcs:disable
    define('PF_DEBUG', true);
    // phpcs:enable
}

$payfastCommon = new PayfastCommon(true);

$payfastCommon->pflog('Payfast ITN call received');

//// Notify Payfast that information has been received
if (!$pfError) {
    header('HTTP/1.0 200 OK');
    flush();
}

//// Get data sent by Payfast
if (!$pfError) {
    $payfastCommon->pflog('Get posted data');

    // Posted variables from ITN
    $pfData = PayfastCommon::pfGetData();

    $payfastCommon->pflog('Payfast Data: ' . json_encode($pfData));

    if ($pfData === false) {
        $pfError  = true;
        $pfErrMsg = PayfastCommon::PF_ERR_BAD_ACCESS;
    }
}

//// Verify security signature
if (!$pfError) {
    $payfastCommon->pflog('Verify security signature');

    // If signature different, log for debugging
    if (!$payfastCommon->pfValidSignature($pfData, $pfParamString, $pfPassphrase)) {
        $pfError  = true;
        $pfErrMsg = PayfastCommon::PF_ERR_INVALID_SIGNATURE;
    }
}

//// Verify data received
if (!$pfError) {
    $payfastCommon->pflog('Verify data received');

    $pfValid = $payfastCommon->pfValidData($moduleInfo, $pfHost, $pfParamString);

    if (!$pfValid) {
        $pfError  = true;
        $pfErrMsg = PayfastCommon::PF_ERR_BAD_ACCESS;
    }
}

//// Create ZenCart order
if (!$pfError) {
    // Variable initialization
    $ts        = time();
    $pfOrderId = null;
    $zcOrderId = null;
    $txnType   = null;

    global $zco_notifier;
    $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_BEGIN');

    // Determine the transaction type
    list($pfOrderId, $zcOrderId, $txnType) = pf_lookupTransaction($pfData);

    $payfastCommon->pflog(
        'Transaction details:' .
        "\n- pfOrderId = " . (empty($pfOrderId) ? 'null' : $pfOrderId) .
        "\n- zcOrderId = " . (empty($zcOrderId) ? 'null' : $zcOrderId) .
        "\n- txnType   = " . (empty($txnType) ? 'null' : $txnType)
    );

    switch ($txnType) {
        /**
         * New Transaction
         *
         * This is for when Zen Cart sees a transaction for the first time.
         * This doesn't necessarily mean that the transaction is in a
         * COMPLETE state, but rather than it is new to the system
         */
        case 'new':
            //// bof: Get Saved Session
            $payfastCommon->pflog('Retrieving saved session');

            // Get the Zen session name and ID from Payfast data
            list($zcSessName, $zcSessID) = explode('=', $pfData['custom_str2']);

            $payfastCommon->pflog('Session Name = ' . $zcSessName . ', Session ID = ' . $zcSessID);

            $sql           =
                'SELECT *
                FROM `' . TABLE_PAYFAST_SESSION . "`
                WHERE `session_id` = '" . $zcSessID . "'";
            $storedSession = $db->Execute($sql);

            if ($storedSession->RecordCount() < 1) {
                $pfError  = true;
                $pfErrMsg = PayfastCommon::PF_ERR_NO_SESSION;
                break;
            } else {
                $_SESSION = unserialize(base64_decode($storedSession->fields['saved_session']));
            }
            //// eof: Get Saved Session

            //// bof: Get ZenCart order details
            $payfastCommon->pflog('Recreating Zen Cart order environment');
            if (defined(DIR_WS_CLASSES)) {
                $payfastCommon->pflog('Additional debug information: DIR_WS_CLASSES is ' . DIR_WS_CLASSES);
            } else {
                $payfastCommon->pflog(' ***ALERT*** DIR_WS_CLASSES IS NOT DEFINED');
            }
            if (isset($_SESSION)) {
                $payfastCommon->pflog('SESSION IS : ' . print_r($_SESSION, true));
            } else {
                $payfastCommon->pflog(' ***ALERT*** $_SESSION IS NOT DEFINED');
            }


            // Load ZenCart shipping class
            require_once DIR_WS_CLASSES . 'Customer.php';
            $payfastCommon->pflog(__FILE__ . LINE_LITERAL . __LINE__);// Load ZenCart shipping class
            require_once DIR_WS_CLASSES . 'shipping.php';
            $payfastCommon->pflog(__FILE__ . LINE_LITERAL . __LINE__);
            // Load ZenCart payment class
            require_once DIR_WS_CLASSES . 'payment.php';
            $payment_modules = new payment($_SESSION['payment']);
            $payfastCommon->pflog(__FILE__ . LINE_LITERAL . __LINE__);
            $shipping_modules = new shipping($_SESSION['shipping']);
            $payfastCommon->pflog(__FILE__ . LINE_LITERAL . __LINE__);
            // Load ZenCart order class
            require_once DIR_WS_CLASSES . 'order.php';
            $order = new order();
            $payfastCommon->pflog(__FILE__ . LINE_LITERAL . __LINE__);
            // Load ZenCart order_total class
            require_once DIR_WS_CLASSES . 'order_total.php';
            $order_total_modules = new order_total();
            $payfastCommon->pflog(__FILE__ . LINE_LITERAL . __LINE__);
            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_BEFORE_ORDER_TOTALS_PROCESS');
            $order_totals = $order_total_modules->process();
            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_TOTALS_PROCESS');
            //// eof: Get ZenCart order details
            $payfastCommon->pflog(__FILE__ . LINE_LITERAL . __LINE__);
            //// bof: Check data against ZenCart order
            $payfastCommon->pflog('Checking data against ZenCart order');

            // Check order amount
            $payfastCommon->pflog('Checking if amounts are the same');
            // patch for multi-currency - AGB 19/07/13 - see also includes/modules/payment/payfast.php
            // if( !PayfastCommon::pfValidSignature( $pfData['amount_gross'], $order->info['total'] ) )
            $amount_gross = round(floatval($pfData['amount_gross']), 2);
            $order_total = round(floatval($_SESSION['payfast_amount']), 2);

            if (!$payfastCommon->pfAmountsEqual($amount_gross, $order_total)) {
                $payfastCommon->pflog(
                    'Amount mismatch: PF amount = ' .
                    $pfData['amount_gross'] . ', ZC amount = ' . $_SESSION['payfast_amount']
                );

                $pfError  = true;
                $pfErrMsg = PayfastCommon::PF_ERR_AMOUNT_MISMATCH;
                break;
            }
            //// eof: Check data against ZenCart order

            // Create ZenCart order
            $payfastCommon->pflog('Creating Zen Cart order');
            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_PAYMENT_MODULES_BEFOREPROCESS');
            $zcOrderId = $order->create($order_totals);
            if ($_SESSION['is_guest_checkout']) {
                // Update the order customer and address details
                updateGuestOrder($zcOrderId, $_SESSION);
            }
            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE');

            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_PAYMENT_MODULES_AFTER_ORDER_CREATE');

            // Create Payfast order
            $payfastCommon->pflog('Creating Payfast order');
            $sqlArray = pf_createOrderArray($pfData, $zcOrderId, $ts);
            zen_db_perform(pf_getActiveTable(), $sqlArray);

            // Create Payfast history record
            $payfastCommon->pflog('Creating Payfast payment status history record');
            $pfOrderId = $db->insert_ID();

            $sqlArray = pf_createOrderHistoryArray($pfData, $pfOrderId, $ts);
            zen_db_perform(TABLE_PAYFAST_PAYMENT_STATUS_HISTORY, $sqlArray);

            // Update order status (if required)
            $newStatus = MODULE_PAYMENT_PF_ORDER_STATUS_ID;

            if ($pfData['payment_status'] == 'PENDING') {
                $payfastCommon->pflog('Setting Zen Cart order status to PENDING');
                $newStatus = MODULE_PAYMENT_PF_PROCESSING_STATUS_ID;

                $sql =
                    'UPDATE ' . TABLE_ORDERS . '
                    SET `orders_status` = ' . MODULE_PAYMENT_PF_PROCESSING_STATUS_ID . "
                    WHERE `orders_id` = '" . $zcOrderId . "'";
                $db->Execute($sql);
            }

            // Update order status history
            $payfastCommon->pflog('Inserting Zen Cart order status history record');

            $sqlArray = [
                'orders_id'         => $zcOrderId,
                'orders_status_id'  => $newStatus,
                'date_added'        => date(PF_FORMAT_DATETIME_DB, $ts),
                'customer_notified' => '0',
                'comments'          => 'Payfast status: ' . $pfData['payment_status'],
            ];
            zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sqlArray);

            // Add products to order
            $payfastCommon->pflog('Adding products to order');
            $order->create_add_products($zcOrderId, 2);
            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS');

            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_SEND_ORDER_EMAIL');

            // Empty cart
            $payfastCommon->pflog('Emptying cart');

            // Deleting stored session information
            $sql =
                'DELETE FROM `' . TABLE_PAYFAST_SESSION . "`
                WHERE `session_id` = '" . $zcSessID . "'";
            $db->Execute($sql);

            break;

        /**
         * Pending transaction must be cleared
         *
         * This is for when there is an existing order in the system which
         * is in a PENDING state which has now been updated to COMPLETE.
         */
        case 'cleared':
            $sqlArray = pf_createOrderHistoryArray($pfData, $pfOrderId, $ts);
            zen_db_perform(TABLE_PAYFAST_PAYMENT_STATUS_HISTORY, $sqlArray);

            $newStatus = MODULE_PAYMENT_PF_ORDER_STATUS_ID;
            break;

        /**
         * Pending transaction must be updated
         *
         * This is when there is an existing order in the system in a PENDING
         * state which is being updated and is STILL in a pending state.
         *
         * NOTE: Currently, this should never happen
         */
        case 'update':
            $sqlArray = pf_createOrderHistoryArray($pfData, $pfOrderId, $ts);
            zen_db_perform(TABLE_PAYFAST_PAYMENT_STATUS_HISTORY, $sqlArray);

            break;

        /**
         * Pending transaction has failed
         *
         * NOTE: Currently, this should never happen
         */
        case 'failed':
            $comments = 'Payment failed (Payfast id = ' . $pfData['pf_payment_id'] . ')';
            $sqlArray = pf_createOrderHistoryArray($pfData, $pfOrderId, $ts);
            zen_db_perform(TABLE_PAYFAST_PAYMENT_STATUS_HISTORY, $sqlArray);

            $newStatus = MODULE_PAYMENT_PF_PREPARE_ORDER_STATUS_ID;

            // Sending email to admin
            $subject = 'Payfast ITN Transaction on your site';
            $body    =
                REGEX_HI_LITERAL .
                "A failed Payfast transaction on your website requires attention\n" .
                LONG_LINE_LITERAL .
                SITE_LITERAL . STORE_NAME . ' (' . HTTP_SERVER . DIR_WS_CATALOG . ")\n" .
                ORDER_LITERAL . $zcOrderId . "\n" .
                //"User ID: ". $db->f( 'user_id' ) ."\n".
                TRANSACTION_LITERAL . $pfData['pf_payment_id'] . "\n" .
                PAYMENT_LITERAL . $pfData['payment_status'] . "\n" .
                'Order Status Code: ' . $newStatus;
            zen_mail(
                STORE_OWNER,
                $pfDebugEmail,
                $subject,
                $body,
                STORE_OWNER,
                STORE_OWNER_EMAIL_ADDRESS,
                null,
                'debug'
            );

            break;

        /**
         * Unknown t
         *
         * NOTE: Currently, this should never happen
         */
        default:
            $payfastCommon->pflog(
                "Can not process for txn type '" . $txn_type . ":\n" .
                print_r($pfData, true)
            );
            break;
    }
}

// Update Zen Cart order and history status tables
if (!$pfError && ($txnType != 'new' && !empty($newStatus))) {
    pf_updateOrderStatusAndHistory($pfData, $zcOrderId, $txnType, $ts, $newStatus);
}

//// Notify Payfast that information has been received
if (!$pfError) {
    header('HTTP/1.0 200 OK');
    flush();
} else {
    header('HTTP/1.1 500 Internal Server Error');
    flush();

    $payfastCommon->pflog('Error occurred: ' . $pfErrMsg);
    $payfastCommon->pflog('Sending email notification');

    $subject = 'Payfast ITN error: ' . $pfErrMsg;
    $body    =
        REGEX_HI_LITERAL .
        "An invalid Payfast transaction on your website requires attention\n" .
        LONG_LINE_LITERAL .
        SITE_LITERAL . STORE_NAME . ' (' . HTTP_SERVER . DIR_WS_CATALOG . ")\n" .
        'Remote IP Address: ' . $_SERVER['REMOTE_ADDR'] . "\n" .
        'Remote host name: ' . gethostbyaddr($_SERVER['REMOTE_ADDR']) . "\n" .
        ORDER_LITERAL . $zcOrderId . "\n";
    if (isset($pfData['pf_payment_id'])) {
        $body .= TRANSACTION_LITERAL . $pfData['pf_payment_id'] . "\n";
    }

    if (isset($pfData['payment_status'])) {
        $body .= PAYMENT_LITERAL . $pfData['payment_status'] . "\n";
    }

    $body .=
        "\nError: " . $pfErrMsg . "\n";

    if ($pfErrMsg === PayfastCommon::PF_ERR_AMOUNT_MISMATCH) {
        $body .=
            'Value received : ' . $pfData['amount_gross'] . "\n" .
            'Value should be: ' . $order->info['total'];
    }

    zen_mail(STORE_OWNER, $pfDebugEmail, $subject, $body, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS, null, 'debug');
}

// Close log
$payfastCommon->pflog('', true);
