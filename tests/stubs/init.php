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
function logActivity($m) { $s = st(); $s['activity'][] = $m; st_save($s); }
function checkCbInvoiceID($id, $name) { return $id; }
function checkCbTransID($t) { $s = st(); foreach ($s['payments'] as $p) { if ($p['trans'] === $t) { die('duplicate'); } } }
function addInvoicePayment($inv, $trans, $amt, $fee, $m) { $s = st(); $s['payments'][] = ['inv' => $inv, 'trans' => $trans, 'amt' => $amt]; st_save($s); }

}
namespace WHMCS\Database {
class Capsule {
    public static function table($t) { return new self($t); }
    private $t; private $w = [];
    function __construct($t) { $this->t = $t; }
    function where($c, $v) { $this->w[$c] = $v; return $this; }
    private function rows() {
        if ($this->t === 'tblaccounts') {
            $r = [];
            foreach (st()['payments'] as $p) { $r[] = ['transid' => $p['trans'], 'invoiceid' => $p['inv'], 'amountin' => $p['amt'], 'amountout' => 0]; }
        } elseif ($this->t === 'tblinvoices') {
            $r = [['id' => 1001, 'userid' => 1, 'status' => 'Unpaid', 'total' => 125.00]];
        } elseif ($this->t === 'tblclients') {
            $r = [['id' => 1, 'email' => 'c@example.com', 'language' => 'bulgarian', 'currency' => 1]];
        } else {
            $r = [['id' => 1, 'code' => 'EUR']];
        }
        return array_values(array_filter($r, function ($row) {
            foreach ($this->w as $c => $v) { if ((string) $row[$c] !== (string) $v) { return false; } }
            return true;
        }));
    }
    function first() { $r = $this->rows(); return $r ? (object) $r[0] : null; }
    function exists() { return (bool) $this->rows(); }
    function value($c) { $r = $this->rows(); return $r ? $r[0][$c] : null; }
    function sum($c) { return array_sum(array_column($this->rows(), $c)); }
}
}
