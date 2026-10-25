<?php
/**
 * DSK Bank V-POS payment gateway for WHMCS.
 *
 * Flow: invoice button -> callback/dskvpos.php?action=pay (register.do) -> hosted page
 *       -> callback/dskvpos.php?invoiceid=N&orderId=... (getOrderStatusExtended.do) -> addInvoicePayment.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/dskvpos/Api.php';

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
    $action = rtrim($params['systemurl'], '/') . '/modules/gateways/callback/dskvpos.php';

    $fields = [
        'action' => 'pay',
        'invoiceid' => $params['invoiceid'],
        'amount' => $minor,
        'currency' => $code,
        'sig' => Api::sign($params['invoiceid'], $minor, $code, $params['password']),
    ];

    $html = '<form method="post" action="' . htmlspecialchars($action) . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars((string) $value) . '">';
    }
    $html .= '<button type="submit" class="btn btn-primary">' . htmlspecialchars($params['langpaynow']) . '</button></form>';

    return $html;
}

function dskvpos_refund($params)
{
    $api = new Api($params);
    $minor = Api::toMinor($params['amount']);

    try {
        $res = $api->refund($params['transid'], $minor);
    } catch (\RuntimeException $e) {
        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }

    $ok = isset($res['errorCode']) ? (string) $res['errorCode'] === '0' : false;

    return [
        'status' => $ok ? 'success' : 'declined',
        'rawdata' => $res,
        'transid' => $params['transid'],
    ];
}
