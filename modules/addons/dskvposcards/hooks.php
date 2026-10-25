<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../gateways/dskvpos/Autopay.php';

use WHMCS\View\Menu\Item as MenuItem;

add_hook('ClientAreaPrimaryNavbar', 1, function (MenuItem $nav) {
    if (!$nav->getChild('Billing')) {
        return;
    }
    $nav->getChild('Billing')->addChild('dskvposcards', [
        'label' => 'Cards and payments',
        'uri' => 'index.php?m=dskvposcards',
        'order' => 90,
    ]);
});

add_hook('DailyCronJob', 1, function () {
    dskvpos_runAutopay();
});
