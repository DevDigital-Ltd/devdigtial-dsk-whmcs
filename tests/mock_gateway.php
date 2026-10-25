<?php
// Fake DSK payment/rest API. State lives in $STATE_DIR/orders.json and bindings.json.
$dir = getenv('STATE_DIR');
$load = function ($n) use ($dir) { $f = "$dir/$n.json"; return is_file($f) ? json_decode(file_get_contents($f), true) : []; };
$save = function ($n, $d) use ($dir) { file_put_contents("$dir/$n.json", json_encode($d)); };
$o = $load('orders');
$b = $load('bindings');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = basename($path);
header('Content-Type: application/json');
$err = function ($c, $t) { echo json_encode(['errorCode' => (string) $c, 'errorMessage' => $t, 'error' => $t]); };
$authed = ($_POST['userName'] ?? '') === 'test-api' && ($_POST['password'] ?? '') === 'secret';

if ($m === 'register.do' || $m === 'registerPreAuth.do') {
    if (!$authed) { $err(5, 'Access denied'); return; }
    $id = 'gw-' . count($o);
    $o[$id] = ['orderNumber' => $_POST['orderNumber'], 'amount' => (int) $_POST['amount'], 'currency' => $_POST['currency'], 'orderStatus' => 0, 'req' => $_POST, 'pre' => $m === 'registerPreAuth.do'];
    $save('orders', $o);
    echo json_encode(['orderId' => $id, 'formUrl' => 'https://uat.example/pay?mdOrder=' . $id]);
} elseif ($m === 'paymentorder.do') {
    $id = $_POST['MDORDER'] ?? '';
    $pan = $_POST['$PAN'] ?? '';
    $store = ($_POST['bindingNotNeeded'] ?? 'false') !== 'true';
    if (!isset($o[$id]) || $pan === '' || empty($_POST['$CVC']) || isset($_POST['PAN'])) { $err(1, 'bad request'); return; }
    if ($store && ($_POST['tii'] ?? '') !== 'CI') { $err(1, 'tii CI required to store credential'); return; }
    if ($pan === '4000000000000002') { $err(5, 'Declined'); return; }
    $o[$id]['pan'] = $pan;
    $o[$id]['bindingNotNeeded'] = $store ? 'false' : 'true';
    $o[$id]['browser'] = $_POST['clientBrowserInfo'] ?? null;
    if (!empty($o[$id]['req']['clientId']) && $store) {
        $bid = 'bind-' . substr($pan, -4);
        $b[$bid] = $o[$id]['req']['clientId'];
        $o[$id]['bindingId'] = $bid;
        $save('bindings', $b);
    }
    $save('orders', $o);
    $force = in_array($o[$id]['req']['features'] ?? '', ['FORCE_TDS', 'FORCE_FULL_TDS'], true);
    if (!$force) { echo json_encode(['errorCode' => '0']); return; }
    if (substr($pan, -4) === '3220') { // 3DS2 with a browser 3DS method step between two calls
        $tid = $_POST['threeDSServerTransID'] ?? '';
        if ($tid === '') {
            echo json_encode(['errorCode' => '0', 'is3DSVer2' => true, 'threeDSServerTransId' => 'tid-' . $id, 'threeDSMethodUrlServer' => 'https://uat.example/method', 'threeDSMethodDataPacked' => 'packed']);
        } elseif ($tid === 'tid-' . $id) {
            echo json_encode(['errorCode' => '0', 'is3DSVer2' => true, 'acsUrl' => 'https://uat.example/acs3', 'packedCReq' => 'creq-' . $id]);
        } else { $err(1, 'bad tid'); }
        return;
    }
    echo json_encode(substr($pan, -4) === '4444'
        ? ['errorCode' => '0', 'is3DSVer2' => true, 'acsUrl' => 'https://uat.example/acs2', 'packedCReq' => 'creq-' . $id]
        : ['errorCode' => '0', 'acsUrl' => 'https://uat.example/acs', 'paReq' => 'pareq', 'termUrl' => 'https://uat.example/term']);
} elseif ($m === 'paymentOrderBinding.do') {
    $id = $_POST['mdOrder'] ?? '';
    $bid = $_POST['bindingId'] ?? '';
    if (!isset($o[$id]) || !isset($b[$bid]) || ($o[$id]['req']['clientId'] ?? '') !== $b[$bid]) { $err(7, 'Unknown binding'); return; }
    $force = in_array($o[$id]['req']['features'] ?? '', ['FORCE_TDS', 'FORCE_FULL_TDS'], true);
    // customer present (3DS forced) = F, no customer = U
    if (($_POST['tii'] ?? '') !== ($force ? 'F' : 'U')) { $err(1, 'bad tii'); return; }
    if (is_file("$dir/decline")) { $err(5, 'Declined'); return; }
    $o[$id]['bindingId'] = $bid;
    if (!$force) { $o[$id]['orderStatus'] = 2; }
    $save('orders', $o);
    echo json_encode($force ? ['errorCode' => '0', 'acsUrl' => 'https://uat.example/acs', 'paReq' => 'pareq', 'termUrl' => 'https://uat.example/term'] : ['errorCode' => '0', 'redirect' => 'https://uat.example/done']);
} elseif ($m === 'getOrderStatusExtended.do') {
    $x = $o[$_POST['orderId']] ?? null;
    if (!$x) { $err(2, 'Unknown order'); return; }
    $r = ['errorCode' => '0', 'orderNumber' => $x['orderNumber'], 'orderStatus' => $x['orderStatus'], 'amount' => $x['amount'], 'currency' => $x['currency']];
    if (!empty($x['bindingId']) && in_array($x['orderStatus'], [1, 2, 3], true)) {
        $r['bindingInfo'] = ['clientId' => $x['req']['clientId'], 'bindingId' => $x['bindingId']];
        $r['cardAuthInfo'] = ['maskedPan' => '411111**' . substr($x['bindingId'], -4), 'expiration' => '203012'];
    }
    echo json_encode($r);
} elseif ($m === 'reverse.do') {
    if (($o[$_POST['orderId']]['orderStatus'] ?? 0) === 1) { $o[$_POST['orderId']]['orderStatus'] = 3; $save('orders', $o); echo json_encode(['errorCode' => '0']); }
    else { $err(7, 'Not authorised'); }
} elseif ($m === 'unBindCard.do') {
    unset($b[$_POST['bindingId'] ?? '']);
    $save('bindings', $b);
    echo json_encode(['errorCode' => '0']);
} elseif ($m === 'refund.do') {
    echo json_encode(($o[$_POST['orderId']]['orderStatus'] ?? 0) === 2 ? ['errorCode' => '0'] : ['errorCode' => '7', 'errorMessage' => 'Not deposited']);
} elseif ($path === '/_set') { // test control: /_set?orderId=..&status=2
    $o[$_GET['orderId']]['orderStatus'] = (int) $_GET['status'];
    $save('orders', $o);
    echo '{}';
} elseif ($path === '/_bindings') {
    echo json_encode(array_keys($b));
} elseif ($path === '/_order') {
    echo json_encode($o[$_GET['orderId']] ?? null);
}
