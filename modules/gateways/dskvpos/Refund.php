<?php

use WHMCS\Database\Capsule;

/**
 * Admin refund and credit helpers for the DSK V-POS addon page.
 * The money movement itself is done by WHMCS (refundInvoicePayment, AddCredit); this only validates first.
 */

/** DSK V-POS payments on an invoice with how much of each can still be refunded. */
function dskvpos_refundable($invoiceId)
{
    $rows = [];
    foreach (Capsule::table('tblaccounts')->where('invoiceid', (int) $invoiceId)->where('gateway', 'dskvpos')->get() as $t) {
        if ((float) $t->amountin <= 0) {
            continue;
        }
        $refunded = (float) Capsule::table('tblaccounts')->where('refundid', (int) $t->id)->sum('amountout');
        $rows[] = [
            'id' => (int) $t->id,
            'transid' => $t->transid,
            'date' => $t->date,
            'paid' => (float) $t->amountin,
            'refunded' => $refunded,
            'refundable' => round((float) $t->amountin - $refunded, 2),
        ];
    }
    return $rows;
}

/**
 * Refunds part or all of one transaction, to the card through the bank or as account credit.
 *
 * @return array ['ok' => bool, 'message' => string]
 */
function dskvpos_adminRefund($invoiceId, $transactionId, $amount, $toCard)
{
    $amount = round((float) $amount, 2);
    $tx = null;
    foreach (dskvpos_refundable($invoiceId) as $row) {
        if ($row['id'] === (int) $transactionId) {
            $tx = $row;
        }
    }
    if (!$tx) {
        return ['ok' => false, 'message' => 'This is not a DSK V-POS payment on that invoice.'];
    }
    if ($amount <= 0 || $amount > $tx['refundable']) {
        return ['ok' => false, 'message' => 'Amount must be between 0.01 and ' . number_format($tx['refundable'], 2, '.', '') . '.'];
    }

    // sendtogateway, addascredit: exactly one of them.
    $result = refundInvoicePayment($tx['id'], $amount, (bool) $toCard, !$toCard, true);

    switch ($result) {
        case 'success':
            return ['ok' => true, 'message' => 'Refunded ' . $amount . ' to the card.'];
        case 'creditsuccess':
            return ['ok' => true, 'message' => 'Added ' . $amount . ' to the client credit.'];
        case 'manual':
            return ['ok' => true, 'message' => 'Recorded as refunded, but the bank was not called. Refund it in the bank portal.'];
        default:
            return ['ok' => false, 'message' => 'The refund failed (' . $result . '). See the Gateway Log.'];
    }
}

/** Adds credit to a client account with no invoice involved. */
function dskvpos_adminAddCredit($clientId, $amount, $description)
{
    $amount = round((float) $amount, 2);
    if (!Capsule::table('tblclients')->where('id', (int) $clientId)->exists() || $amount <= 0) {
        return ['ok' => false, 'message' => 'Unknown client or amount not above zero.'];
    }
    $r = localAPI('AddCredit', [
        'clientid' => (int) $clientId,
        'amount' => $amount,
        'description' => $description !== '' ? $description : 'Credit added by admin',
    ]);
    return ($r['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => 'Added ' . $amount . ' credit to client #' . (int) $clientId . '.']
        : ['ok' => false, 'message' => 'Could not add credit: ' . ($r['message'] ?? 'unknown error')];
}
