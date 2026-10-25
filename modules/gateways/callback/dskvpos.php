<?php
/**
 * DSK V-POS: customer return after 3-D Secure. Payments start in dskvpos_card.php.
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

function dskvpos_redirectInvoice($systemUrl, $invoiceId, $paymentSuccess = null)
{
    $url = $systemUrl . '/viewinvoice.php?id=' . (int) $invoiceId;
    if ($paymentSuccess !== null) {
        $url .= $paymentSuccess ? '&paymentsuccess=true' : '&paymentfailed=true';
    }
    header('Location: ' . $url);
    exit;
}

// ---- Customer return -------------------------------------------------------
$invoiceId = (int) ($_GET['invoiceid'] ?? 0);
$orderId = (string) ($_GET['orderId'] ?? '');

if ($invoiceId <= 0 || !preg_match('/^[0-9a-zA-Z-]{1,64}$/', $orderId)) {
    dskvpos_redirectInvoice($systemUrl, $invoiceId);
}

if (dskvpos_rateLimited('return_' . ($_SERVER['REMOTE_ADDR'] ?? '?'), 30, 60)) {
    http_response_code(429);
    die('Too many requests');
}

// Answer from local data when possible so random requests do not become bank API calls.
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    dskvpos_redirectInvoice($systemUrl, $invoiceId, false);
}
if (Capsule::table('tblaccounts')->where('transid', $orderId)->where('invoiceid', $invoiceId)->exists()) {
    dskvpos_redirectInvoice($systemUrl, $invoiceId, true);
}
if ($invoice->status !== 'Unpaid') {
    dskvpos_redirectInvoice($systemUrl, $invoiceId);
}

$result = dskvpos_settleOrder($api, $orderId, $gatewayParams, $invoiceId);

dskvpos_redirectInvoice($systemUrl, $invoiceId, $result['paid']);
