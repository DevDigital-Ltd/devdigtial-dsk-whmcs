<?php
/**
 * Client area for DSK V-POS: saved cards, monthly autopay, account credit.
 * Provided by DevDigital Ltd.
 * Pages open at index.php?m=dskvposcards. Card payments themselves run in the gateway module.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../gateways/dskvpos/Api.php';
require_once __DIR__ . '/../../gateways/dskvpos/Cards.php';
require_once __DIR__ . '/../../gateways/dskvpos/Refund.php';

use WHMCS\Module\Gateway\DskVpos\Api;

function dskvposcards_config()
{
    return [
        'name' => 'DSK V-POS Cards',
        'description' => 'Saved cards, automatic monthly payment and account credit for the DSK Bank V-POS gateway.',
        'version' => '1.1.0',
        'author' => 'DevDigital Ltd',
    ];
}

function dskvposcards_activate()
{
    try {
        dskvpos_ensureSchema();
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => $e->getMessage()];
    }
    return ['status' => 'success', 'description' => 'Tables created. Enable credit in General Settings → Credit so customers can add funds.'];
}

function dskvposcards_clientarea($vars)
{
    require_once ROOTDIR . '/includes/gatewayfunctions.php';

    $clientId = dskvpos_currentClientId();
    $gw = getGatewayVariables('dskvpos');
    $flash = null;

    if ($clientId && !empty($gw['type']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string) ($_POST['do'] ?? '');
        $cardId = (int) ($_POST['card_id'] ?? 0);
        $card = $cardId ? dskvpos_cardOf($clientId, $cardId) : null;

        if ($act === 'remove' && $card) {
            $gw['testMode'] = $gw['testMode'] === 'on' || $gw['testMode'] === 'Yes';
            try {
                (new Api($gw))->unbind($card->binding_id);
            } catch (\RuntimeException $e) {
                // The local copy is removed anyway; the card can no longer be used from here.
            }
            dskvpos_deleteCard($clientId, $cardId);
            $flash = 'removed';
        } elseif ($act === 'autopay_on' && $card) {
            dskvpos_setAutopay($clientId, $cardId);
            $flash = 'autopay_on';
        } elseif ($act === 'autopay_off') {
            dskvpos_setAutopay($clientId, null);
            $flash = 'autopay_off';
        }
    }

    $client = $clientId ? \WHMCS\User\Client::find($clientId) : null;
    $cards = [];
    foreach ($clientId ? dskvpos_clientCards($clientId) : [] as $c) {
        $cards[] = [
            'id' => $c->id,
            'masked' => $c->masked_pan,
            'expiry' => substr($c->expiry, 4, 2) . '/' . substr($c->expiry, 0, 4),
            'autopay' => (bool) $c->autopay,
        ];
    }

    $bg = $client && stripos((string) $client->language, 'bulgarian') !== false;

    return [
        'pagetitle' => $bg ? 'Карти и плащания' : 'Cards and payments',
        'breadcrumb' => ['index.php?m=dskvposcards' => $bg ? 'Карти и плащания' : 'Cards and payments'],
        'templatefile' => 'templates/cards',
        'requirelogin' => true,
        'vars' => [
            'bg' => $bg,
            'cards' => $cards,
            'hasAutopay' => (bool) array_filter($cards, function ($c) { return $c['autopay']; }),
            'status' => $flash ?: (string) ($_GET['status'] ?? ''),
            'credit' => $client ? (string) new \WHMCS\View\Formatter\Price($client->credit, getCurrency($clientId)) : '',
            'addFundsUrl' => 'clientarea.php?action=addfunds',
            'addAction' => rtrim($gw['systemurl'] ?? '', '/') . '/modules/gateways/callback/dskvpos_card.php',
            'csrf' => Api::csrf($gw['password'] ?? ''),
            'gatewayActive' => !empty($gw['type']),
        ],
    ];
}

/** Admin page: Addons -> DSK V-POS Cards. Refund a DSK V-POS payment or add credit to a client. */
function dskvposcards_output($vars)
{
    $h = static function ($v) {
        return htmlspecialchars((string) $v);
    };
    $token = generate_token('plain');
    $self = $vars['modulelink'];
    $msg = '';
    $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $admin = isset($_SESSION['adminid']) ? \WHMCS\User\Admin::find($_SESSION['adminid']) : null;
        $who = $admin ? $admin->username : 'admin';
        $do = (string) ($_POST['do'] ?? '');

        if ($do === 'refund') {
            $r = dskvpos_adminRefund($invoiceId, (int) $_POST['transaction'], $_POST['amount'] ?? 0, ($_POST['method'] ?? '') === 'card');
            if ($r['ok']) {
                logActivity('DSK V-POS: ' . $who . ' refunded on invoice #' . $invoiceId . ': ' . $r['message']);
            }
        } elseif ($do === 'credit') {
            $r = dskvpos_adminAddCredit((int) ($_POST['clientid'] ?? 0), $_POST['amount'] ?? 0, trim((string) ($_POST['description'] ?? '')));
            if ($r['ok']) {
                logActivity('DSK V-POS: ' . $who . ' added credit: ' . $r['message']);
            }
        }
        if (isset($r)) {
            $msg = '<div class="' . ($r['ok'] ? 'successbox' : 'errorbox') . '"><strong>' . $h($r['message']) . '</strong></div>';
        }
    }

    echo $msg;

    echo '<h2>Refund an invoice payment</h2>'
        . '<form method="get" action="' . $h($self) . '">'
        . '<input type="hidden" name="module" value="dskvposcards">'
        . 'Invoice # <input type="number" name="invoiceid" value="' . ($invoiceId ?: '') . '" class="form-control input-inline" style="width:120px;display:inline"> '
        . '<button class="btn btn-default">Find payments</button></form>';

    if ($invoiceId) {
        $rows = dskvpos_refundable($invoiceId);
        if (!$rows) {
            echo '<p>No DSK V-POS payments on invoice #' . $invoiceId . '.</p>';
        } else {
            echo '<form method="post" action="' . $h($self) . '&invoiceid=' . $invoiceId . '">'
                . '<input type="hidden" name="token" value="' . $h($token) . '"><input type="hidden" name="do" value="refund">'
                . '<table class="datatable" width="100%"><tr><th></th><th>Date</th><th>Transaction</th><th>Paid</th><th>Refunded</th><th>Can refund</th></tr>';
            foreach ($rows as $i => $r) {
                echo '<tr><td><input type="radio" name="transaction" value="' . $r['id'] . '"' . ($i === 0 ? ' checked' : '') . '></td>'
                    . '<td>' . $h($r['date']) . '</td><td>' . $h($r['transid']) . '</td><td>' . number_format($r['paid'], 2) . '</td>'
                    . '<td>' . number_format($r['refunded'], 2) . '</td><td>' . number_format($r['refundable'], 2) . '</td></tr>';
            }
            echo '</table><p>Amount <input name="amount" class="form-control input-inline" style="width:120px;display:inline" required> '
                . '<label><input type="radio" name="method" value="card" checked> Back to the card (through the bank)</label> '
                . '<label><input type="radio" name="method" value="credit"> As client credit</label> '
                . '<button class="btn btn-primary" onclick="return confirm(\'Refund now?\')">Refund</button></p></form>';
        }
    }

    echo '<h2>Add credit to a client</h2>'
        . '<form method="post" action="' . $h($self) . '"><input type="hidden" name="token" value="' . $h($token) . '"><input type="hidden" name="do" value="credit">'
        . 'Client ID <input type="number" name="clientid" class="form-control input-inline" style="width:120px;display:inline" required> '
        . 'Amount <input name="amount" class="form-control input-inline" style="width:120px;display:inline" required> '
        . 'Note <input name="description" class="form-control input-inline" style="width:260px;display:inline" maxlength="100"> '
        . '<button class="btn btn-primary" onclick="return confirm(\'Add credit now?\')">Add credit</button></form>';
}
