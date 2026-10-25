<?php

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\DskVpos\Api;

/**
 * Amount still due (minor units) and the ISO numeric currency code the invoice must be paid in.
 *
 * @return array|null ['balance' => int, 'currency' => string|null]
 */
function dskvpos_invoiceExpectation($invoiceId)
{
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return null;
    }
    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    $code = $client ? Capsule::table('tblcurrencies')->where('id', $client->currency)->value('code') : null;
    $paid = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin')
        - (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountout');

    return [
        'balance' => Api::toMinor((float) $invoice->total - $paid),
        'currency' => $code ? Api::currencyCode($code) : null,
    ];
}

/**
 * Re-reads the order from the gateway and records the payment if it is really paid.
 * Shared by the customer return and the server-to-server notification.
 *
 * @param int|null $expectInvoice invoice the caller believes the order belongs to
 * @return array ['paid' => bool, 'invoiceId' => int]
 */
function dskvpos_settleOrder(Api $api, $orderId, array $gatewayParams, $expectInvoice = null)
{
    $module = 'dskvpos';

    try {
        $status = $api->status($orderId);
    } catch (\RuntimeException $e) {
        logTransaction($module, $e->getMessage(), 'Status error');
        return ['paid' => false, 'invoiceId' => (int) $expectInvoice];
    }

    // orderNumber is "<invoiceid>-<timestamp>"
    $orderNumber = (string) ($status['orderNumber'] ?? '');
    $invoiceId = (int) strtok($orderNumber, '-');
    $orderStatus = isset($status['orderStatus']) ? (int) $status['orderStatus'] : -1;

    if ($invoiceId <= 0 || ($expectInvoice !== null && $invoiceId !== (int) $expectInvoice)) {
        logTransaction($module, $status, 'Order/invoice mismatch');
        return ['paid' => false, 'invoiceId' => (int) $expectInvoice];
    }

    // 2 = captured. 1 = pre-authorized only: not treated as paid.
    if ($orderStatus !== 2) {
        logTransaction($module, $status, 'Unsuccessful (orderStatus ' . $orderStatus . ')');
        return ['paid' => false, 'invoiceId' => $invoiceId];
    }

    // Return page and notification race: whichever runs second must not die() in checkCbTransID.
    if (Capsule::table('tblaccounts')->where('transid', $orderId)->exists()) {
        return ['paid' => true, 'invoiceId' => $invoiceId];
    }

    $expected = dskvpos_invoiceExpectation($invoiceId);
    $paidMinor = (int) ($status['amount'] ?? 0);
    if (!$expected || $expected['currency'] === null
        || (string) ($status['currency'] ?? '') !== $expected['currency']
        || $paidMinor <= 0 || $paidMinor > $expected['balance']) {
        logTransaction($module, $status, 'Amount/currency mismatch, not credited');
        logActivity('DSK V-POS: order ' . $orderId . ' for invoice #' . $invoiceId
            . ' was captured by the bank but NOT credited (amount/currency mismatch). Check manually.');
        return ['paid' => false, 'invoiceId' => $invoiceId];
    }

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
    checkCbTransID($orderId);

    logTransaction($module, $status, 'Successful');
    addInvoicePayment($invoiceId, $orderId, ((int) $status['amount']) / 100, 0, $module);

    return ['paid' => true, 'invoiceId' => $invoiceId];
}
