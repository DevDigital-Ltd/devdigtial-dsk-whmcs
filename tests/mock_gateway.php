<?php
// Fake DSK payment/rest API. Order state lives in $STATE_DIR/orders.json.
$f = getenv('STATE_DIR') . '/orders.json';
$o = is_file($f) ? json_decode(file_get_contents($f), true) : [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (basename($path) === 'register.do') {
    if ($_POST['userName'] !== 'test-api' || $_POST['password'] !== 'secret') { echo json_encode(['errorCode' => '5', 'errorMessage' => 'Access denied']); return; }
    $id = 'gw-' . count($o);
    $o[$id] = ['orderNumber' => $_POST['orderNumber'], 'amount' => (int) $_POST['amount'], 'currency' => $_POST['currency'], 'orderStatus' => 0, 'req' => $_POST];
    file_put_contents($f, json_encode($o));
    echo json_encode(['orderId' => $id, 'formUrl' => 'https://uat.example/pay?mdOrder=' . $id]);
} elseif (basename($path) === 'getOrderStatusExtended.do') {
    $x = $o[$_POST['orderId']] ?? null;
    echo json_encode($x ? ['errorCode' => '0', 'orderNumber' => $x['orderNumber'], 'orderStatus' => $x['orderStatus'], 'amount' => $x['amount'], 'currency' => $x['currency']] : ['errorCode' => '2', 'errorMessage' => 'Unknown order']);
} elseif (basename($path) === 'refund.do') {
    echo json_encode(($o[$_POST['orderId']]['orderStatus'] ?? 0) === 2 ? ['errorCode' => '0'] : ['errorCode' => '7', 'errorMessage' => 'Not deposited']);
} elseif ($path === '/_set') { // test control: /_set?orderId=..&status=2
    $o[$_GET['orderId']]['orderStatus'] = (int) $_GET['status'];
    file_put_contents($f, json_encode($o));
    echo '{}';
}
