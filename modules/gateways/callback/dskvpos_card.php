<?php
/**
 * DSK V-POS card payments from our own form: pay an invoice (new or saved card) and add a card.
 * Card data is posted here over HTTPS, forwarded to the bank and never logged or stored.
 * The result is always re-read from the bank on return.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../dskvpos/Api.php';
require_once __DIR__ . '/../dskvpos/Settle.php';
require_once __DIR__ . '/../dskvpos/Pay.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\DskVpos\Api;

$gatewayParams = getGatewayVariables('dskvpos');
if (!$gatewayParams['type']) {
    die('Module not activated');
}
$gatewayParams['testMode'] = $gatewayParams['testMode'] === 'on' || $gatewayParams['testMode'] === 'Yes';
$api = new Api($gatewayParams);
$systemUrl = rtrim($gatewayParams['systemurl'], '/');
$self = $systemUrl . '/modules/gateways/callback/dskvpos_card.php';
$cardsUrl = $systemUrl . '/index.php?m=dskvposcards';
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';
$extraUrls = ['methodNotifyUrl' => $self . '?action=3ds2m', 'stepUrl' => $self, 'sealKey' => $gatewayParams['password']];
$browser = ($gatewayParams['browserInfo'] ?? '') === 'on' ? dskvpos_browserInfo($_POST) : [];

function dskvpos_go($url)
{
    header('Location: ' . $url);
    exit;
}

function dskvpos_invoiceUrl($systemUrl, $invoiceId, $ok = null)
{
    return $systemUrl . '/viewinvoice.php?id=' . (int) $invoiceId . ($ok === null ? '' : ($ok ? '&paymentsuccess=true' : '&paymentfailed=true'));
}

// ---- 3-D Secure 2 method notification (inside the hidden iframe)
if (($_REQUEST['action'] ?? '') === '3ds2m') {
    // Notification URL loaded inside the hidden iframe by the issuer's 3DS method.
    echo '<!doctype html><script>try{parent.dskvposDone&&parent.dskvposDone();}catch(e){}</script>';
    exit;
}

// ---- Return from 3-D Secure after adding a card -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orderId = (string) ($_GET['orderId'] ?? '');
    if (($_GET['action'] ?? '') !== 'return' || !preg_match('/^[0-9a-zA-Z-]{1,64}$/', $orderId)) {
        dskvpos_go($cardsUrl);
    }
    if (dskvpos_rateLimited('cardreturn_' . $ip, 30, 60)) {
        http_response_code(429);
        die('Too many requests');
    }
    $ok = dskvpos_settleCardOrder($api, $orderId);
    dskvpos_go($cardsUrl . '&status=' . ($ok ? 'added' : 'failed'));
}

// ---- 3-D Secure 2: browser finished the 3DS method -------------------------------
if (($_POST['action'] ?? '') === '3ds2') {
    $d = Api::unseal($_POST['blob'] ?? '', $gatewayParams['password']);
    if ($d === null || !hash_equals((string) ($d['sid'] ?? ''), md5((string) session_id())) || dskvpos_rateLimited('card3ds2_' . $ip, 20, 600)) {
        dskvpos_go($systemUrl . '/clientarea.php');
    }
    dskvpos_finish3ds2($api, $d);
    dskvpos_go((string) $d['fail']);
}

// ---- Card forms --------------------------------------------------------------
$action = (string) ($_POST['action'] ?? '');
if (!in_array($action, ['pay', 'add'], true)) {
    http_response_code(400);
    die('Invalid request');
}
if (!hash_equals(Api::csrf($gatewayParams['password']), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    die('Invalid request');
}
// Card numbers must never travel over plain HTTP.
if (stripos($systemUrl, 'https://') !== 0 && !$gatewayParams['testMode']) {
    http_response_code(400);
    die('HTTPS required');
}
// Card-testing protection: few attempts per address.
if (dskvpos_rateLimited('cardform_' . $ip, 10, 600)) {
    http_response_code(429);
    die('Too many requests');
}

$currentClient = dskvpos_currentClientId();

if ($action === 'pay') {
    $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
    $minor = (int) ($_POST['amount'] ?? 0);
    $currency = (string) ($_POST['currency'] ?? '');
    $expected = Api::sign($invoiceId, $minor, $currency, $gatewayParams['password']);
    if (!hash_equals($expected, (string) ($_POST['sig'] ?? '')) || $minor <= 0) {
        http_response_code(400);
        die('Invalid request');
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice || $invoice->status !== 'Unpaid') {
        dskvpos_go(dskvpos_invoiceUrl($systemUrl, $invoiceId));
    }
    $failed = dskvpos_invoiceUrl($systemUrl, $invoiceId, false);

    $cardId = (int) ($_POST['card_id'] ?? 0);
    $save = !empty($_POST['save_card']);
    // Saved cards and saving a card need the owner to be logged in.
    if (($cardId || $save) && $currentClient !== (int) $invoice->userid) {
        dskvpos_go($failed);
    }
    if ($currentClient && dskvpos_rateLimited('cardclient_' . $currentClient, 10, 600)) {
        dskvpos_go($failed);
    }

    $bindingId = null;
    $card = null;
    if ($cardId) {
        $saved = dskvpos_cardOf($currentClient, $cardId);
        if (!$saved) {
            dskvpos_go($failed);
        }
        $bindingId = $saved->binding_id;
    } else {
        $card = dskvpos_parseCard($_POST);
        if (!$card) {
            dskvpos_go($failed);
        }
    }

    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    $self1 = $systemUrl . '/modules/gateways/callback/dskvpos.php';
    dskvpos_startPayment($api, [
        'orderNumber' => $invoiceId . '-' . time(),
        'minor' => $minor,
        'currency' => $currency,
        'returnUrl' => $self1 . '?invoiceid=' . $invoiceId,
        'failUrl' => $self1 . '?invoiceid=' . $invoiceId . '&failed=1',
        'description' => 'Invoice #' . $invoiceId,
        'lang' => ($client && stripos((string) $client->language, 'bulgarian') !== false) ? 'bg' : 'en',
        'email' => $client ? $client->email : null,
        'callbackUrl' => $systemUrl . '/modules/gateways/callback/dskvpos_notify.php',
        'clientId' => (int) $invoice->userid,
        'card' => $card,
        'bindingId' => $bindingId,
        'save' => $save,
        'browser' => $browser,
    ] + $extraUrls);
    dskvpos_go($failed);
}

// action === 'add': verify the card with a released pre-authorisation, then keep the binding
if (!$currentClient) {
    dskvpos_go($systemUrl . '/clientarea.php');
}
$client = Capsule::table('tblclients')->where('id', $currentClient)->first();
$code = $client ? Capsule::table('tblcurrencies')->where('id', $client->currency)->value('code') : null;
$currency = $code ? Api::currencyCode($code) : null;
$minor = Api::toMinor((string) (($gatewayParams['verifyAmount'] ?? '') !== '' ? $gatewayParams['verifyAmount'] : '1.00'));
$card = dskvpos_parseCard($_POST);
if ($currency === null || $minor <= 0 || !$card || dskvpos_rateLimited('cardclient_' . $currentClient, 10, 600)) {
    dskvpos_go($cardsUrl . '&status=failed');
}

dskvpos_startPayment($api, [
    'orderNumber' => 'card-' . $currentClient . '-' . time(),
    'minor' => $minor,
    'currency' => $currency,
    'returnUrl' => $self . '?action=return',
    'failUrl' => $cardsUrl . '&status=failed',
    'description' => 'Card verification',
    'lang' => stripos((string) $client->language, 'bulgarian') !== false ? 'bg' : 'en',
    'email' => $client->email,
    'callbackUrl' => null,
    'clientId' => $currentClient,
    'card' => $card,
    'bindingId' => null,
    'save' => true,
    'preAuth' => true,
    'browser' => $browser,
] + $extraUrls);
dskvpos_go($cardsUrl . '&status=failed');
