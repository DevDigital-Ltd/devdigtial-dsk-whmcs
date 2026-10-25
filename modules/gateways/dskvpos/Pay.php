<?php

use WHMCS\Module\Gateway\DskVpos\Api;

/**
 * Starts a customer-present payment from our own form and hands the browser to the issuer's 3-D Secure page.
 *
 * $o keys: orderNumber, minor, currency, returnUrl, failUrl, description, lang, email, callbackUrl,
 *          clientId (int|0), card (array|null), bindingId (string|null), save (bool), preAuth (bool),
 *          browser (clientBrowserInfo array), methodNotifyUrl, stepUrl, sealKey (API password)
 *
 * @return string|null error text for the log, null when the browser was redirected (never returns then)
 */
function dskvpos_startPayment(Api $api, array $o)
{
    $bind = !empty($o['bindingId']) || !empty($o['save']) || !empty($o['preAuth']);
    $extra = ($bind && !empty($o['clientId'])) ? ['clientId' => dskvpos_clientRef($o['clientId'])] : [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $save = !empty($o['save']) || !empty($o['preAuth']);
    $notify = !empty($o['methodNotifyUrl']) ? ['threeDSMethodNotificationUrl' => $o['methodNotifyUrl']] : [];

    try {
        $reg = $api->register(
            $o['orderNumber'], $o['minor'], $o['currency'], $o['returnUrl'], $o['failUrl'],
            $o['description'], $o['lang'], $o['email'], $o['callbackUrl'], $extra, true, !empty($o['preAuth'])
        );
        if (empty($reg['orderId'])) {
            logTransaction('dskvpos', $reg, 'Register failed');
            return 'register';
        }

        if (!empty($o['bindingId'])) {
            $res = $api->paymentOrderBinding($reg['orderId'], $o['bindingId'], $o['lang'], $ip, $o['email'], 'F', $o['browser'] ?? [], $notify);
        } else {
            $res = $api->paymentOrder($reg['orderId'], $o['card'], $o['lang'], $ip, $o['email'], $save, $o['browser'] ?? [], $notify);
        }
    } catch (\RuntimeException $e) {
        logTransaction('dskvpos', $e->getMessage(), 'Payment start error');
        return 'transport';
    }

    logTransaction('dskvpos', $res, 'Payment start ' . $o['orderNumber']);

    if ((string) ($res['errorCode'] ?? '1') !== '0') {
        return 'declined';
    }

    // 3-D Secure 2 without browser data: the browser must run the 3DS method first (see dskvpos_methodPage).
    if (!empty($res['is3DSVer2']) && empty($res['acsUrl']) && !empty($res['threeDSServerTransId']) && !empty($res['threeDSMethodUrlServer'])) {
        dskvpos_methodPage($api, $res, $reg['orderId'], $o, $save, $ip);
    }

    return dskvpos_dispatch3ds($res, $reg['orderId'], $o['returnUrl']);
}

/**
 * Sends the browser to the issuer's 3-D Secure page, or on to the return URL when no 3-D Secure step is needed.
 * With features=FORCE_TDS the bank fails a payment that skipped 3-D Secure, and the return handler
 * still re-reads the order from the bank before crediting anything.
 *
 * @return string|null error for the log; does not return when the browser was redirected
 */
function dskvpos_dispatch3ds(array $res, $orderId, $returnUrl)
{
    $acs = (string) ($res['acsUrl'] ?? '');
    if ($acs !== '' && stripos($acs, 'https://') === 0) {
        // 3-D Secure 1 sends PaReq/MD/TermUrl, 3-D Secure 2 sends the packed challenge request as creq.
        if (!empty($res['packedCReq'])) {
            $fields = ['creq' => $res['packedCReq']];
        } elseif (!empty($res['paReq'])) {
            $fields = ['PaReq' => $res['paReq'], 'MD' => $orderId, 'TermUrl' => (string) ($res['termUrl'] ?? $returnUrl)];
        } else {
            logTransaction('dskvpos', $res, '3DS response not understood');
            return '3ds';
        }
        echo '<!doctype html><html><body onload="document.forms[0].submit()"><form method="post" action="' . htmlspecialchars($acs) . '">';
        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars((string) $value) . '">';
        }
        echo '<noscript><button type="submit">Continue</button></noscript></form></body></html>';
        exit;
    }

    if (!empty($res['is3DSVer2'])) {
        logTransaction('dskvpos', $res, '3DS2 step not completed');
        return '3ds2';
    }

    header('Location: ' . $returnUrl . (strpos($returnUrl, '?') === false ? '?' : '&') . 'orderId=' . rawurlencode($orderId));
    exit;
}

/**
 * Runs the 3-D Secure 2 method in a hidden iframe, then posts back to the 3ds2 step.
 * The card data travels in an encrypted, 10-minute blob through the customer's own browser, so nothing is stored on the server.
 */
function dskvpos_methodPage(Api $api, array $res, $orderId, array $o, $save, $ip)
{
    $url = (string) $res['threeDSMethodUrlServer'];
    if (stripos($url, 'https://') !== 0 || empty($o['stepUrl'])) {
        logTransaction('dskvpos', $res, '3DS method URL not usable');
        return;
    }

    $blob = Api::seal([
        'order' => $orderId,
        'tid' => (string) $res['threeDSServerTransId'],
        'card' => $o['card'] ?? null,
        'binding' => $o['bindingId'] ?? null,
        'save' => $save,
        'lang' => $o['lang'],
        'email' => $o['email'],
        'ip' => $ip,
        'browser' => $o['browser'] ?? [],
        'return' => $o['returnUrl'],
        'fail' => $o['failUrl'],
        'sid' => md5((string) session_id()),
    ], $o['sealKey']);

    $h = static function ($v) {
        return htmlspecialchars((string) $v);
    };
    echo '<!doctype html><html><body>'
        . '<form id="m" method="post" action="' . $h($url) . '" target="dskvpos3ds"><input type="hidden" name="threeDSMethodData" value="' . $h($res['threeDSMethodDataPacked'] ?? '') . '"></form>'
        . '<iframe name="dskvpos3ds" style="display:none" width="0" height="0"></iframe>'
        . '<form id="n" method="post" action="' . $h($o['stepUrl']) . '"><input type="hidden" name="action" value="3ds2"><input type="hidden" name="blob" value="' . $h($blob) . '">'
        . '<noscript><button type="submit">Continue</button></noscript></form>'
        . '<script>var d=false;function dskvposDone(){if(d)return;d=true;document.getElementById("n").submit();}'
        . 'document.getElementById("m").submit();setTimeout(dskvposDone,8000);</script></body></html>';
    exit;
}

/** Second call of a 3-D Secure 2 payment, after the 3DS method ran. Same shape as dskvpos_startPayment's result. */
function dskvpos_finish3ds2(Api $api, array $d)
{
    $extra = ['threeDSServerTransID' => $d['tid']];
    try {
        if (!empty($d['binding'])) {
            $res = $api->paymentOrderBinding($d['order'], $d['binding'], $d['lang'], $d['ip'], $d['email'], 'F', $d['browser'] ?? [], $extra);
        } else {
            $res = $api->paymentOrder($d['order'], $d['card'], $d['lang'], $d['ip'], $d['email'], !empty($d['save']), $d['browser'] ?? [], $extra);
        }
    } catch (\RuntimeException $e) {
        logTransaction('dskvpos', $e->getMessage(), '3DS2 step error');
        return 'transport';
    }
    logTransaction('dskvpos', $res, '3DS2 step ' . $d['order']);
    if ((string) ($res['errorCode'] ?? '1') !== '0') {
        return 'declined';
    }
    return dskvpos_dispatch3ds($res, $d['order'], $d['return']);
}

/** clientBrowserInfo for 3-D Secure 2: server-side headers plus values the form script filled in. */
function dskvpos_browserInfo(array $post)
{
    $j = json_decode((string) ($post['browser_info'] ?? ''), true);
    $j = is_array($j) ? $j : [];

    return [
        'userAgent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'browserAcceptHeader' => substr((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 0, 255),
        'browserIpAddress' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'browserLanguage' => substr(preg_replace('/[^A-Za-z-]/', '', (string) ($j['language'] ?? 'en')), 0, 8),
        'screenWidth' => (int) ($j['sw'] ?? 0),
        'screenHeight' => (int) ($j['sh'] ?? 0),
        'colorDepth' => (int) ($j['cd'] ?? 24),
        'browserTimeZoneOffset' => (int) ($j['tz'] ?? 0),
        'javaEnabled' => !empty($j['java']),
        'javascriptEnabled' => true,
    ];
}

/** Script that fills the hidden browser_info field of the closest card form. */
function dskvpos_browserScript()
{
    return '<script>(function(){var s=document.currentScript,f=s.closest("form")||s.previousElementSibling;'
        . 'var i=f&&f.querySelector("input[name=browser_info]");if(!i)return;'
        . 'i.value=JSON.stringify({language:navigator.language||"en",sw:screen.width,sh:screen.height,cd:screen.colorDepth,'
        . 'tz:new Date().getTimezoneOffset(),java:!!navigator.javaEnabled&&navigator.javaEnabled()});})();</script>';
}
