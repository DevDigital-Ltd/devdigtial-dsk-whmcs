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

use WHMCS\Module\Gateway\DskVpos\Api;

function dskvposcards_config()
{
    return [
        'name' => 'DSK V-POS Cards',
        'description' => 'Saved cards, automatic monthly payment and account credit for the DSK Bank V-POS gateway.',
        'version' => '1.0',
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
