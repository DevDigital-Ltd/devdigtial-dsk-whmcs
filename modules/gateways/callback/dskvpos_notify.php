<?php
/**
 * DSK V-POS server-to-server notification (dynamicCallbackUrl).
 * Symmetric checksum: HMAC-SHA256 over "name;value;" pairs sorted by name, uppercase hex.
 * Even with a valid checksum the order is re-read from the gateway before crediting.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../dskvpos/Api.php';
require_once __DIR__ . '/../dskvpos/Settle.php';

use WHMCS\Module\Gateway\DskVpos\Api;

$gatewayParams = getGatewayVariables('dskvpos');
if (!$gatewayParams['type']) {
    http_response_code(404);
    die('Module not activated');
}
$gatewayParams['testMode'] = $gatewayParams['testMode'] === 'on' || $gatewayParams['testMode'] === 'Yes';

$data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$received = strtoupper((string) ($data['checksum'] ?? ''));
unset($data['checksum'], $data['sign_alias']);

$secret = (string) $gatewayParams['callbackToken'];
if ($secret === '' || $received === '') {
    http_response_code(403);
    die('Checksum required');
}

ksort($data);
$payload = '';
foreach ($data as $name => $value) {
    $payload .= $name . ';' . $value . ';';
}

if (!hash_equals(strtoupper(hash_hmac('sha256', $payload, $secret)), $received)) {
    logTransaction('dskvpos', $_REQUEST, 'Notification: bad checksum');
    http_response_code(403);
    die('Bad checksum');
}

logTransaction('dskvpos', $_REQUEST, 'Notification: ' . ($data['operation'] ?? '?'));

if (($data['operation'] ?? '') === 'deposited' && (string) ($data['status'] ?? '') === '1' && !empty($data['mdOrder'])) {
    dskvpos_settleOrder(new Api($gatewayParams), (string) $data['mdOrder'], $gatewayParams);
}

echo 'OK';
