<?php
/**
 * DSK Bank V-POS payment gateway for WHMCS.
 * Provided by DevDigital Ltd.
 *
 * Flow: card form on the invoice -> callback/dskvpos_card.php (register.do + paymentOrder.do, 3-D Secure forced)
 *       -> issuer 3-D Secure page -> callback/dskvpos.php?invoiceid=N&orderId=... (getOrderStatusExtended.do)
 *       -> addInvoicePayment. Saved cards, autopay and card verification live in modules/addons/dskvposcards.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/dskvpos/Api.php';
require_once __DIR__ . '/dskvpos/Cards.php';
require_once __DIR__ . '/dskvpos/Pay.php';

use WHMCS\Module\Gateway\DskVpos\Api;

function dskvpos_MetaData()
{
    return [
        'DisplayName' => 'DSK Bank V-POS',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function dskvpos_notifyUrl()
{
    return rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/')
        . '/modules/gateways/callback/dskvpos_notify.php';
}

function dskvpos_config()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'DSK Bank V-POS'],
        'provider' => [
            'FriendlyName' => 'Provider',
            'Type' => 'System',
            'Value' => 'DevDigital Ltd',
        ],
        'userName' => [
            'FriendlyName' => 'API username',
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'Merchant API account (ends with -api)',
        ],
        'password' => [
            'FriendlyName' => 'API password',
            'Type' => 'password',
            'Size' => '30',
        ],
        'callbackToken' => [
            'FriendlyName' => 'Callback secret',
            'Type' => 'password',
            'Size' => '40',
            'Description' => 'Symmetric key for callback checksum (HMAC-SHA256), from DSK support',
        ],
        'testMode' => [
            'FriendlyName' => 'Test mode',
            'Type' => 'yesno',
            'Description' => 'Use uat.dskbank.bg sandbox',
        ],
        'verifyAmount' => [
            'FriendlyName' => 'Card verification amount',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '1.00',
            'Description' => 'Authorised and released at once when a customer saves a card (client currency)',
        ],
        'tdsMode' => [
            'FriendlyName' => '3-D Secure mode',
            'Type' => 'dropdown',
            'Options' => 'FORCE_TDS,FORCE_FULL_TDS',
            'Description' => 'FORCE_TDS: payment fails if the card cannot do 3-D Secure. FORCE_FULL_TDS: the issuer must also confirm the cardholder (stricter, more declines)',
        ],
        'browserInfo' => [
            'FriendlyName' => 'Send browser data (3-D Secure 2)',
            'Type' => 'yesno',
            'Description' => 'Needs clientBrowserInfo enabled by DSK. If off, the browser runs the 3DS method step instead',
        ],
        'notifyUrl' => [
            'FriendlyName' => 'Notification URL',
            'Type' => 'System',
            'Value' => '<input type="text" class="form-control" readonly onclick="this.select()" value="'
                . htmlspecialchars(dskvpos_notifyUrl()) . '"><small>Give this URL to DSK support.</small>',
        ],
    ];
}

function dskvpos_link($params)
{
    $code = Api::currencyCode($params['currency']);
    if ($code === null) {
        return '<div class="alert alert-danger">Currency ' . htmlspecialchars($params['currency']) . ' is not supported by DSK V-POS.</div>';
    }

    $minor = Api::toMinor($params['amount']);
    $action = rtrim($params['systemurl'], '/') . '/modules/gateways/callback/dskvpos_card.php';
    $lang = stripos((string) ($params['clientdetails']['language'] ?? ''), 'bulgarian') !== false ? 'bg' : 'en';
    $t = function ($k) use ($lang) {
        return htmlspecialchars(dskvpos_t($k, $lang));
    };

    $fields = [
        'action' => 'pay',
        'invoiceid' => $params['invoiceid'],
        'amount' => $minor,
        'currency' => $code,
        'sig' => Api::sign($params['invoiceid'], $minor, $code, $params['password']),
        'csrf' => Api::csrf($params['password']),
    ];

    $owner = (int) ($params['clientdetails']['userid'] ?? 0);
    $loggedIn = $owner > 0 && dskvpos_currentClientId() === $owner;
    $cards = $loggedIn ? dskvpos_clientCards($owner) : [];

    $h = '<form method="post" action="' . htmlspecialchars($action) . '" autocomplete="on" class="dskvpos-form" style="max-width:420px;text-align:left">';
    foreach ($fields as $name => $value) {
        $h .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars((string) $value) . '">';
    }

    foreach ($cards as $c) {
        $h .= '<div class="radio"><label><input type="radio" name="card_id" value="' . (int) $c->id . '"' . ($c->autopay ? ' checked' : '') . '> '
            . $t('saved') . ' ' . htmlspecialchars($c->masked_pan) . ' ('
            . htmlspecialchars(substr($c->expiry, 4, 2) . '/' . substr($c->expiry, 0, 4)) . ')</label></div>';
    }
    if ($cards) {
        $h .= '<div class="radio"><label><input type="radio" name="card_id" value="0"> ' . $t('newcard') . '</label></div>';
    }

    $in = 'class="form-control" style="margin-bottom:6px"';
    $h .= '<div class="dskvpos-new">'
        . '<input ' . $in . ' name="card_holder" maxlength="26" autocomplete="cc-name" placeholder="' . $t('holder') . '">'
        . '<input ' . $in . ' name="card_number" inputmode="numeric" maxlength="23" autocomplete="cc-number" placeholder="' . $t('number') . '">'
        . '<div style="display:flex;gap:6px">'
        . '<input ' . $in . ' name="card_month" inputmode="numeric" maxlength="2" autocomplete="cc-exp-month" placeholder="' . $t('month') . '">'
        . '<input ' . $in . ' name="card_year" inputmode="numeric" maxlength="4" autocomplete="cc-exp-year" placeholder="' . $t('year') . '">'
        . '<input ' . $in . ' name="card_cvc" inputmode="numeric" maxlength="4" autocomplete="cc-csc" placeholder="' . $t('cvc') . '">'
        . '</div>';
    if ($loggedIn) {
        $h .= '<div class="checkbox"><label><input type="checkbox" name="save_card" value="1"> ' . $t('save') . '</label></div>';
    }
    $h .= '</div><input type="hidden" name="browser_info" value="">';

    $h .= '<button type="submit" class="btn btn-primary">' . htmlspecialchars($params['langpaynow']) . '</button>'
        . '<p class="text-muted" style="margin-top:8px"><small>' . $t('secure') . '</small></p></form>';

    $h .= dskvpos_browserScript();

    // Hide the new-card fields while a saved card is selected (the server still validates everything).
    if ($cards) {
        $h .= '<script>(function(){var f=document.querySelector(".dskvpos-form"),n=f.querySelector(".dskvpos-new");'
            . 'function u(){var r=f.querySelector("input[name=card_id]:checked");n.style.display=(r&&r.value!=="0")?"none":"";}'
            . 'f.addEventListener("change",u);u();})();</script>';
    }

    return $h;
}

/**
 * Admin "Refund" on a transaction (full or partial). WHMCS also offers "add to credit" in the same dialog,
 * which needs no bank call. The order is re-read first so the bank is only asked for a refund that can succeed.
 */
function dskvpos_refund($params)
{
    $api = new Api($params);
    $minor = Api::toMinor($params['amount']);
    $orderId = (string) $params['transid'];

    try {
        $status = $api->status($orderId);
    } catch (\RuntimeException $e) {
        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }

    $orderStatus = isset($status['orderStatus']) ? (int) $status['orderStatus'] : -1;
    $code = Api::currencyCode($params['currency']);
    // 2 = captured, 4 = already partially refunded.
    if (!in_array($orderStatus, [2, 4], true) || $minor <= 0 || $minor > (int) ($status['amount'] ?? 0)
        || ($code !== null && (string) ($status['currency'] ?? '') !== $code)) {
        return ['status' => 'declined', 'rawdata' => $status, 'transid' => $orderId];
    }

    try {
        $res = $api->refund($orderId, $minor);
    } catch (\RuntimeException $e) {
        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }

    $ok = isset($res['errorCode']) ? (string) $res['errorCode'] === '0' : false;

    return [
        'status' => $ok ? 'success' : 'declined',
        'rawdata' => $res,
        'transid' => $orderId,
    ];
}
