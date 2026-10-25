<?php

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\DskVpos\Api;

require_once __DIR__ . '/Cards.php';

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

    // orderNumber is "<invoiceid>-<timestamp>", or "card-<clientid>-<timestamp>" for card verification
    $orderNumber = (string) ($status['orderNumber'] ?? '');
    if (strpos($orderNumber, 'card-') === 0) {
        return ['paid' => false, 'invoiceId' => 0];
    }
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

    // Serialise per order: return page and notification can arrive at the same moment.
    $lock = 'dskvpos_' . md5($orderId);
    $got = Capsule::select('SELECT GET_LOCK(?, 10) AS l', [$lock]);
    if (empty($got) || (int) $got[0]->l !== 1) {
        logTransaction($module, $status, 'Lock timeout');
        return ['paid' => false, 'invoiceId' => $invoiceId];
    }

    try {
        return dskvpos_creditOrder($status, $orderId, $invoiceId, $gatewayParams);
    } finally {
        Capsule::select('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Must run under the per-order lock. */
function dskvpos_creditOrder(array $status, $orderId, $invoiceId, array $gatewayParams)
{
    $module = 'dskvpos';

    // Already recorded by the other path: not an error, and checkCbTransID() would die().
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
    addInvoicePayment($invoiceId, $orderId, $paidMinor / 100, 0, $module);

    // The customer ticked "save card": the bank returns a binding only then.
    $owner = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid');
    if ($owner) {
        dskvpos_saveBinding($owner, $status);
    }

    return ['paid' => true, 'invoiceId' => $invoiceId];
}

/**
 * Card verification order (pre-authorisation, then released). Saves the binding.
 * The client comes from the order number and is re-checked against the bank's clientId.
 *
 * @return bool true when the card is saved
 */
function dskvpos_settleCardOrder(Api $api, $orderId)
{
    $module = 'dskvpos';

    try {
        $status = $api->status($orderId);
    } catch (\RuntimeException $e) {
        logTransaction($module, $e->getMessage(), 'Card status error');
        return false;
    }

    if (!preg_match('/^card-(\d+)-\d+$/', (string) ($status['orderNumber'] ?? ''), $m)) {
        return false;
    }
    $clientId = (int) $m[1];
    $orderStatus = isset($status['orderStatus']) ? (int) $status['orderStatus'] : -1;

    $lock = 'dskvpos_' . md5($orderId);
    $got = Capsule::select('SELECT GET_LOCK(?, 10) AS l', [$lock]);
    if (empty($got) || (int) $got[0]->l !== 1) {
        return false;
    }

    try {
        // 1 = authorised. 3 = already released by an earlier call (page refresh).
        if ($orderStatus !== 1 && $orderStatus !== 3) {
            logTransaction($module, $status, 'Card not verified (orderStatus ' . $orderStatus . ')');
            return false;
        }
        $cardId = dskvpos_saveBinding($clientId, $status);
        if ($cardId === null) {
            logTransaction($module, $status, 'Card verification: no binding for client');
            return false;
        }
        if ($orderStatus === 1) {
            try {
                $res = $api->reverse($orderId);
            } catch (\RuntimeException $e) {
                $res = ['errorCode' => 'x'];
            }
            if ((string) ($res['errorCode'] ?? '') !== '0') {
                logTransaction($module, $res, 'Card verification hold not released');
                logActivity('DSK V-POS: verification hold ' . $orderId . ' for client #' . $clientId
                    . ' could not be released automatically. Reverse it in the bank portal.');
            }
        }
        logTransaction($module, $status, 'Card saved');
        return true;
    } finally {
        Capsule::select('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/**
 * Fixed-window counter per key, kept in the system temp dir.
 *
 * @return bool true when the caller is over the limit
 */
function dskvpos_rateLimited($key, $max, $windowSeconds)
{
    $file = sys_get_temp_dir() . '/dskvpos_rl_' . md5($key);
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return false;
    }
    flock($fh, LOCK_EX);
    $parts = explode('|', (string) stream_get_contents($fh));
    $count = (int) ($parts[0] ?? 0);
    $start = (int) ($parts[1] ?? 0);
    $now = time();
    if ($now - $start >= $windowSeconds) {
        $count = 0;
        $start = $now;
    }
    $count++;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $count . '|' . $start);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $count > $max;
}
