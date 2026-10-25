<?php

use WHMCS\Module\Gateway\DskVpos\Api;

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

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
    checkCbTransID($orderId);

    logTransaction($module, $status, 'Successful');
    addInvoicePayment($invoiceId, $orderId, ((int) $status['amount']) / 100, 0, $module);

    return ['paid' => true, 'invoiceId' => $invoiceId];
}
