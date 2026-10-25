<?php
/**
 * DSK V-POS: start payment (action=pay) and handle customer return from the hosted page.
 * The payment result is never trusted from the URL; it is re-read via getOrderStatusExtended.do.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../dskvpos/Api.php';
require_once __DIR__ . '/../dskvpos/Settle.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\DskVpos\Api;

$gatewayModuleName = 'dskvpos';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module not activated');
}

$gatewayParams['testMode'] = $gatewayParams['testMode'] === 'on' || $gatewayParams['testMode'] === 'Yes';
$api = new Api($gatewayParams);
$systemUrl = rtrim($gatewayParams['systemurl'], '/');
$self = $systemUrl . '/modules/gateways/callback/dskvpos.php';

function dskvpos_redirectInvoice($systemUrl, $invoiceId, $paymentSuccess = null)
{
    $url = $systemUrl . '/viewinvoice.php?id=' . (int) $invoiceId;
    if ($paymentSuccess !== null) {
        $url .= $paymentSuccess ? '&paymentsuccess=true' : '&paymentfailed=true';
    }
    header('Location: ' . $url);
    exit;
}

// ---- Start payment ---------------------------------------------------------
if (($_POST['action'] ?? '') === 'pay') {
    $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
    $minor = (int) ($_POST['amount'] ?? 0);
    $currency = (string) ($_POST['currency'] ?? '');
    $sig = (string) ($_POST['sig'] ?? '');

    $expected = hash_hmac('sha256', implode('|', [$invoiceId, $minor, $currency]), $gatewayParams['password']);
    if (!hash_equals($expected, $sig) || $minor <= 0) {
        http_response_code(400);
        die('Invalid request');
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice || $invoice->status !== 'Unpaid') {
        dskvpos_redirectInvoice($systemUrl, $invoiceId);
    }

    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    $lang = ($client && stripos((string) $client->language, 'bulgarian') !== false) ? 'bg' : 'en';

    $orderNumber = $invoiceId . '-' . time();
    $returnUrl = $self . '?invoiceid=' . $invoiceId;
    $failUrl = $self . '?invoiceid=' . $invoiceId . '&failed=1';

    try {
        $res = $api->register(
            $orderNumber,
            $minor,
            $currency,
            $returnUrl,
            $failUrl,
            'Invoice #' . $invoiceId,
            $lang,
            $client ? $client->email : null,
            $systemUrl . '/modules/gateways/callback/dskvpos_notify.php'
        );
    } catch (\RuntimeException $e) {
        logTransaction($gatewayModuleName, $e->getMessage(), 'Register error');
        dskvpos_redirectInvoice($systemUrl, $invoiceId, false);
    }

    if (empty($res['formUrl'])) {
        logTransaction($gatewayModuleName, $res, 'Register failed');
        dskvpos_redirectInvoice($systemUrl, $invoiceId, false);
    }

    logTransaction($gatewayModuleName, $res, 'Register ' . $orderNumber);
    header('Location: ' . $res['formUrl']);
    exit;
}

// ---- Customer return -------------------------------------------------------
$invoiceId = (int) ($_GET['invoiceid'] ?? 0);
$orderId = (string) ($_GET['orderId'] ?? '');

if ($invoiceId <= 0 || $orderId === '') {
    dskvpos_redirectInvoice($systemUrl, $invoiceId);
}

$result = dskvpos_settleOrder($api, $orderId, $gatewayParams, $invoiceId);

dskvpos_redirectInvoice($systemUrl, $invoiceId, $result['paid']);
