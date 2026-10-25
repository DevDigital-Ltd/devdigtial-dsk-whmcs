<?php
namespace {
// Minimal WHMCS stand-in for offline tests.
define('WHMCS', true);
define('STATE', getenv('STATE_DIR') . '/state.json');

function st() { return is_file(STATE) ? json_decode(file_get_contents(STATE), true) : ['payments' => [], 'log' => []]; }
function st_save($s) { file_put_contents(STATE, json_encode($s)); }

function getGatewayVariables($m) {
    return [
        'type' => 'CC', 'name' => 'DSK Bank V-POS', 'systemurl' => getenv('SYSTEM_URL'),
        'userName' => getenv('DSK_USER') ?: 'test-api', 'password' => getenv('DSK_PASS') ?: 'secret', 'callbackToken' => 'cbsecret', 'testMode' => 'on',
    ];
}
function logTransaction($m, $data, $result) { $s = st(); $s['log'][] = $result; st_save($s); }
function checkCbInvoiceID($id, $name) { return $id; }
function checkCbTransID($t) { $s = st(); foreach ($s['payments'] as $p) { if ($p['trans'] === $t) { die('duplicate'); } } }
function addInvoicePayment($inv, $trans, $amt, $fee, $m) { $s = st(); $s['payments'][] = ['inv' => $inv, 'trans' => $trans, 'amt' => $amt]; st_save($s); }

}
namespace WHMCS\Database {
class Capsule {
    public static function table($t) { return new self($t); }
    private $t; function __construct($t) { $this->t = $t; }
    function where($c, $v) { return $this; }
    function first() {
        return $this->t === 'tblinvoices'
            ? (object) ['id' => 1001, 'userid' => 1, 'status' => 'Unpaid']
            : (object) ['id' => 1, 'email' => 'c@example.com', 'language' => 'bulgarian'];
    }
}
}
