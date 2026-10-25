<?php

namespace WHMCS\Module\Gateway\DskVpos;

/**
 * Thin client for the DSK V-POS REST API (register, paymentOrder, bindings, status, refund, reverse).
 */
class Api
{
    const URL_TEST = 'https://uat.dskbank.bg/payment/rest/';
    const URL_LIVE = 'https://epg.dskbank.bg/payment/rest/';

    /** ISO 4217 numeric codes */
    const CURRENCIES = [
        'EUR' => '978',
        'USD' => '840',
        'GBP' => '826',
    ];

    private $baseUrl;
    private $userName;
    private $password;
    private $tdsMode;

    public function __construct(array $params)
    {
        $this->baseUrl = !empty($params['testMode']) ? self::URL_TEST : self::URL_LIVE;
        $this->userName = trim($params['userName']);
        $this->password = $params['password'];
        // FORCE_FULL_TDS also requires the issuer to confirm the cardholder (PaRes Y).
        $this->tdsMode = (($params['tdsMode'] ?? '') === 'FORCE_FULL_TDS') ? 'FORCE_FULL_TDS' : 'FORCE_TDS';
    }

    /** Encrypts data for the round trip through the customer's browser (3-D Secure 2 method step). Nothing is stored. */
    public static function seal(array $data, $apiPassword)
    {
        $key = hash_hmac('sha256', 'dskvpos-seal-v1', (string) $apiPassword, true);
        $iv = random_bytes(12);
        $ct = openssl_encrypt(json_encode($data + ['exp' => time() + 600]), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return rtrim(strtr(base64_encode($iv . $tag . $ct), '+/', '-_'), '=');
    }

    /** @return array|null null when tampered with or older than 10 minutes */
    public static function unseal($blob, $apiPassword)
    {
        $raw = base64_decode(strtr((string) $blob, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $key = hash_hmac('sha256', 'dskvpos-seal-v1', (string) $apiPassword, true);
        $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $d = $json === false ? null : json_decode($json, true);
        return (is_array($d) && ($d['exp'] ?? 0) >= time()) ? $d : null;
    }

    public static function currencyCode($iso)
    {
        $iso = strtoupper($iso);
        return isset(self::CURRENCIES[$iso]) ? self::CURRENCIES[$iso] : null;
    }

    /** Signs the pay request. The key is derived so the API password itself is never used as an HMAC key. */
    public static function sign($invoiceId, $minorAmount, $currencyCode, $apiPassword)
    {
        $key = hash_hmac('sha256', 'dskvpos-pay-v1', (string) $apiPassword);
        return hash_hmac('sha256', implode('|', [(int) $invoiceId, (int) $minorAmount, $currencyCode]), $key);
    }

    /** Decimal amount to minor units without float rounding errors. */
    public static function toMinor($amount)
    {
        return (int) round(((float) $amount) * 100);
    }

    /** CSRF token bound to the PHP session, for the card forms. */
    public static function csrf($apiPassword)
    {
        $key = hash_hmac('sha256', 'dskvpos-csrf-v1', (string) $apiPassword);
        return hash_hmac('sha256', (string) session_id(), $key);
    }

    /** Luhn check on a digits-only card number. */
    public static function luhn($pan)
    {
        $sum = 0;
        foreach (array_reverse(str_split($pan)) as $i => $d) {
            $d = (int) $d;
            if ($i % 2 === 1) {
                $d *= 2;
                $d = $d > 9 ? $d - 9 : $d;
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }

    /**
     * Orders always ask for 3-D Secure when $force3ds is set (customer present).
     * $extra: clientId (creates a card binding on success).
     */
    public function register($orderNumber, $minorAmount, $currencyCode, $returnUrl, $failUrl, $description, $language, $email = null, $callbackUrl = null, array $extra = [], $force3ds = false, $preAuth = false)
    {
        $data = [
            'orderNumber' => $orderNumber,
            'amount' => $minorAmount,
            'currency' => $currencyCode,
            'returnUrl' => $returnUrl,
            'failUrl' => $failUrl,
            'description' => $description,
            'language' => $language,
        ];
        if ($email) {
            $data['email'] = $email;
        }
        if ($callbackUrl) {
            $data['dynamicCallbackUrl'] = $callbackUrl;
        }
        if ($force3ds) {
            // The payment fails when the card cannot do 3-D Secure.
            $data['features'] = $this->tdsMode;
        }
        return $this->call($preAuth ? 'registerPreAuth.do' : 'register.do', $data + $extra);
    }

    /**
     * Pays a registered order with a card entered on our own form. Card data is never logged or stored.
     * tii CI = first payment, credential is stored. Empty = plain payment, nothing stored.
     * $browser: clientBrowserInfo for 3-D Secure 2 (needs the matching setting on the merchant account).
     */
    public function paymentOrder($mdOrder, array $card, $language, $ip, $email, $saveCard, array $browser = [], array $extra = [])
    {
        $data = [
            'MDORDER' => $mdOrder,
            '$PAN' => $card['pan'],
            '$CVC' => $card['cvc'],
            'YYYY' => $card['year'],
            'MM' => $card['month'],
            'TEXT' => $card['holder'],
            'language' => $language,
            'ip' => $ip,
            'email' => $email,
            'bindingNotNeeded' => $saveCard ? 'false' : 'true',
        ];
        if ($saveCard) {
            $data['tii'] = 'CI';
        }
        if ($browser) {
            $data['clientBrowserInfo'] = json_encode($browser);
        }
        return $this->call('paymentorder.do', $data + $extra);
    }

    /** $tii F = customer picked a stored card and is present. U = merchant-initiated, no customer. */
    public function paymentOrderBinding($mdOrder, $bindingId, $language, $ip, $email, $tii, array $browser = [], array $extra = [])
    {
        $data = [
            'mdOrder' => $mdOrder,
            'bindingId' => $bindingId,
            'tii' => $tii,
            'language' => $language,
            'ip' => $ip,
            'email' => $email,
        ];
        if ($browser) {
            $data['clientBrowserInfo'] = json_encode($browser);
        }
        return $this->call('paymentOrderBinding.do', $data + $extra);
    }

    public function unbind($bindingId)
    {
        return $this->call('unBindCard.do', ['bindingId' => $bindingId]);
    }

    public function reverse($orderId)
    {
        return $this->call('reverse.do', ['orderId' => $orderId]);
    }

    public function status($orderId)
    {
        return $this->call('getOrderStatusExtended.do', ['orderId' => $orderId]);
    }

    public function refund($orderId, $minorAmount)
    {
        return $this->call('refund.do', ['orderId' => $orderId, 'amount' => $minorAmount]);
    }

    /**
     * @return array decoded JSON response
     * @throws \RuntimeException on transport or decode failure
     */
    private function call($method, array $data)
    {
        $data['userName'] = $this->userName;
        $data['password'] = $this->password;

        $ch = curl_init($this->baseUrl . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('V-POS connection error: ' . $err);
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('V-POS invalid response: ' . substr($body, 0, 200));
        }
        return $json;
    }
}
