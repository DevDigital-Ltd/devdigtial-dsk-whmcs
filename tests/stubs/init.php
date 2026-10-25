<?php
namespace {
// Minimal WHMCS stand-in for offline tests.
define('WHMCS', true);
define('STATE', getenv('STATE_DIR') . '/state.json');

function st() { $s = is_file(STATE) ? json_decode(file_get_contents(STATE), true) : []; return $s + ['payments' => [], 'log' => [], 'tables' => []]; }
function st_save($s) { file_put_contents(STATE, json_encode($s)); }

function getGatewayVariables($m) {
    return [
        'type' => 'CC', 'verifyAmount' => '1.00', 'browserInfo' => 'on', 'name' => 'DSK Bank V-POS', 'systemurl' => getenv('SYSTEM_URL'),
        'userName' => getenv('DSK_USER') ?: 'test-api', 'password' => getenv('DSK_PASS') ?: 'secret', 'callbackToken' => 'cbsecret', 'testMode' => 'on',
    ];
}
function logTransaction($m, $data, $result) { $s = st(); $s['log'][] = $result; st_save($s); }
function logActivity($m) { $s = st(); $s['activity'][] = $m; st_save($s); }
function sendMessage($t, $id) { $s = st(); $s['mail'][] = $t . ':' . $id; st_save($s); }
function localAPI($c, $a) { return ['result' => 'success']; }
function checkCbInvoiceID($id, $name) { return $id; }
function checkCbTransID($t) { $s = st(); foreach ($s['payments'] as $p) { if ($p['trans'] === $t) { die('duplicate'); } } }
function addInvoicePayment($inv, $trans, $amt, $fee, $m) { usleep(300000); $s = st(); $s['payments'][] = ['inv' => $inv, 'trans' => $trans, 'amt' => $amt]; st_save($s); }

}
namespace WHMCS\Authentication {
class CurrentUser { function client() { return !empty($_COOKIE['uid']) ? (object) ['id' => (int) $_COOKIE['uid'], 'currency' => 1] : null; } }
}
namespace WHMCS\Database {
class Schema { function hasTable($t) { return true; } function create($t, $f) {} }
class Capsule {
    public static function table($t) { return new self($t); }
    public static function schema() { return new Schema(); }
    private $t; private $w = [];
    function __construct($t) { $this->t = $t; }
    function where($c, $o, $v = null) { if (func_num_args() === 2) { $v = $o; $o = '='; } $this->w[] = [$c, $o, $v]; return $this; }
    function orderBy($c) { return $this; }
    private function base() {
        if ($this->t === 'tblaccounts') {
            $r = [];
            foreach (st()['payments'] as $p) { $r[] = ['transid' => $p['trans'], 'invoiceid' => $p['inv'], 'amountin' => $p['amt'], 'amountout' => 0]; }
            return $r;
        } elseif ($this->t === 'tblinvoices') {
            return [
                ['id' => 1001, 'userid' => 1, 'status' => 'Unpaid', 'total' => 126.00, 'paymentmethod' => 'dskvpos', 'duedate' => '2020-01-01'],
                ['id' => 1002, 'userid' => 1, 'status' => 'Unpaid', 'total' => 40.00, 'paymentmethod' => 'dskvpos', 'duedate' => '2020-01-01'],
                ['id' => 1003, 'userid' => 1, 'status' => 'Unpaid', 'total' => 10.00, 'paymentmethod' => 'dskvpos', 'duedate' => '2020-01-01'],
            ];
        } elseif ($this->t === 'tblclients') {
            return [['id' => 1, 'email' => 'c@example.com', 'language' => 'bulgarian', 'currency' => 1, 'credit' => 0]];
        } elseif (strpos($this->t, 'mod_') === 0) {
            return st()['tables'][$this->t] ?? [];
        }
        return [['id' => 1, 'code' => 'EUR']];
    }
    private function rows() {
        return array_values(array_filter($this->base(), function ($row) {
            foreach ($this->w as [$c, $o, $v]) {
                $a = $row[$c] ?? null;
                $ok = $o === '<=' ? (string) $a <= (string) $v : (string) $a === (string) $v;
                if (!$ok) { return false; }
            }
            return true;
        }));
    }
    private function saveTable($rows) { $s = st(); $s['tables'][$this->t] = array_values($rows); st_save($s); }
    private static $locks = [];
    static function select($q, $b = []) {
        $f = getenv('STATE_DIR') . '/lock_' . md5($b[0]);
        if (getenv('NOLOCK')) { return [(object) ['l' => 1]]; }
        if (strpos($q, 'GET_LOCK') !== false) { $h = fopen($f, 'c'); flock($h, LOCK_EX); self::$locks[$b[0]] = $h; return [(object) ['l' => 1]]; }
        if (isset(self::$locks[$b[0]])) { flock(self::$locks[$b[0]], LOCK_UN); fclose(self::$locks[$b[0]]); unset(self::$locks[$b[0]]); }
        return [(object) ['l' => 1]];
    }
    function first() { $r = $this->rows(); return $r ? (object) $r[0] : null; }
    function get() { return array_map(function ($r) { return (object) $r; }, $this->rows()); }
    function exists() { return (bool) $this->rows(); }
    function value($c) { $r = $this->rows(); return $r ? $r[0][$c] : null; }
    function sum($c) { return array_sum(array_column($this->rows(), $c)); }
    function insertGetId($d) { $all = $this->base(); $d['id'] = count($all) ? max(array_column($all, 'id')) + 1 : 1; $all[] = $d; $this->saveTable($all); return $d['id']; }
    function insert($d) { $all = $this->base(); $all[] = $d; $this->saveTable($all); return true; }
    function update($d) {
        $all = $this->base();
        foreach ($all as &$row) { foreach ($this->rows() as $m) { if ($m === $row) { $row = $d + $row; break; } } }
        $this->saveTable($all);
    }
    function delete() { $all = $this->base(); $hit = $this->rows(); $this->saveTable(array_filter($all, function ($r) use ($hit) { return !in_array($r, $hit, true); })); }
}
}
