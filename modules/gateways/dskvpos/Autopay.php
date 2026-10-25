<?php

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\DskVpos\Api;

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Settle.php';

const DSKVPOS_AUTOPAY_MAX_ATTEMPTS = 3;

/**
 * Daily: for clients with an autopay card, apply credit first, then charge the card for what is left
 * on every due DSK V-POS invoice. No customer is present, so no 3-D Secure step is possible here;
 * the card was verified with 3-D Secure when it was saved. If the bank asks for 3-D Secure the
 * attempt counts as failed and the customer gets the standard payment-failed email.
 */
function dskvpos_runAutopay()
{
    require_once ROOTDIR . '/includes/gatewayfunctions.php';
    require_once ROOTDIR . '/includes/invoicefunctions.php';

    $gw = getGatewayVariables('dskvpos');
    if (empty($gw['type'])) {
        return;
    }
    $gw['testMode'] = $gw['testMode'] === 'on' || $gw['testMode'] === 'Yes';
    $api = new Api($gw);
    $systemUrl = rtrim($gw['systemurl'], '/');

    dskvpos_ensureSchema();
    foreach (Capsule::table('mod_dskvpos_cards')->where('autopay', 1)->get() as $card) {
        $invoices = Capsule::table('tblinvoices')
            ->where('userid', $card->client_id)->where('status', 'Unpaid')
            ->where('paymentmethod', 'dskvpos')->where('duedate', '<=', date('Y-m-d'))->get();
        foreach ($invoices as $invoice) {
            dskvpos_autopayInvoice($api, $gw, $systemUrl, $card, $invoice);
        }
    }
}

function dskvpos_autopayInvoice(Api $api, array $gw, $systemUrl, $card, $invoice)
{
    $invoiceId = (int) $invoice->id;
    $lock = 'dskvpos_auto_' . $invoiceId;
    $got = Capsule::select('SELECT GET_LOCK(?, 0) AS l', [$lock]);
    if (empty($got) || (int) $got[0]->l !== 1) {
        return;
    }

    try {
        $row = Capsule::table('mod_dskvpos_autopay')->where('invoice_id', $invoiceId)->first();
        if ($row && ((int) $row->attempts >= DSKVPOS_AUTOPAY_MAX_ATTEMPTS || $row->last_attempt === date('Y-m-d'))) {
            return;
        }

        // Customer credit first.
        $credit = (float) Capsule::table('tblclients')->where('id', $card->client_id)->value('credit');
        $expected = dskvpos_invoiceExpectation($invoiceId);
        if ($credit > 0 && $expected && $expected['balance'] > 0) {
            localAPI('ApplyCredit', ['invoiceid' => $invoiceId, 'amount' => min($credit, $expected['balance'] / 100), 'noemail' => true]);
            $expected = dskvpos_invoiceExpectation($invoiceId);
        }
        if (!$expected || $expected['balance'] <= 0 || $expected['currency'] === null) {
            return;
        }

        // Count the attempt before charging so a crash cannot cause endless retries.
        $attempts = $row ? (int) $row->attempts + 1 : 1;
        if ($row) {
            Capsule::table('mod_dskvpos_autopay')->where('invoice_id', $invoiceId)
                ->update(['attempts' => $attempts, 'last_attempt' => date('Y-m-d')]);
        } else {
            Capsule::table('mod_dskvpos_autopay')->insert(['invoice_id' => $invoiceId, 'attempts' => 1, 'last_attempt' => date('Y-m-d')]);
        }

        $client = Capsule::table('tblclients')->where('id', $card->client_id)->first();
        $lang = ($client && stripos((string) $client->language, 'bulgarian') !== false) ? 'bg' : 'en';
        $self = $systemUrl . '/modules/gateways/callback/dskvpos.php?invoiceid=' . $invoiceId;
        $error = null;
        $paid = false;

        try {
            $reg = $api->register(
                $invoiceId . '-' . time(), $expected['balance'], $expected['currency'], $self, $self . '&failed=1',
                'Invoice #' . $invoiceId, $lang, $client ? $client->email : null,
                $systemUrl . '/modules/gateways/callback/dskvpos_notify.php',
                ['clientId' => dskvpos_clientRef($card->client_id)]
            );
            if (empty($reg['orderId'])) {
                $error = 'register failed';
            } else {
                $res = $api->paymentOrderBinding($reg['orderId'], $card->binding_id, $lang, '', $client ? $client->email : null, 'U');
                logTransaction('dskvpos', $res, 'Autopay invoice ' . $invoiceId);
                if ((string) ($res['errorCode'] ?? '1') !== '0') {
                    $error = 'declined';
                } elseif (!empty($res['acsUrl'])) {
                    $error = '3-D Secure required';
                } else {
                    $paid = dskvpos_settleOrder($api, $reg['orderId'], $gw, $invoiceId)['paid'];
                    $error = $paid ? null : 'not captured';
                }
            }
        } catch (\RuntimeException $e) {
            $error = 'bank unreachable';
            logTransaction('dskvpos', $e->getMessage(), 'Autopay error');
        }

        if ($error === null) {
            Capsule::table('mod_dskvpos_autopay')->where('invoice_id', $invoiceId)->update(['last_error' => null]);
        } else {
            Capsule::table('mod_dskvpos_autopay')->where('invoice_id', $invoiceId)->update(['last_error' => $error]);
            logActivity('DSK V-POS autopay failed for invoice #' . $invoiceId . ' (attempt ' . $attempts . '): ' . $error);
            sendMessage('Credit Card Payment Failed', $invoiceId);
        }
    } finally {
        Capsule::select('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
