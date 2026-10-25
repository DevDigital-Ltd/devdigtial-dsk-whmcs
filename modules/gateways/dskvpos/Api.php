<?php

namespace WHMCS\Module\Gateway\DskVpos;

/**
 * Thin client for the DSK V-POS REST API (register.do, getOrderStatusExtended.do, refund.do).
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

    public function __construct(array $params)
    {
        $this->baseUrl = !empty($params['testMode']) ? self::URL_TEST : self::URL_LIVE;
        $this->userName = trim($params['userName']);
        $this->password = $params['password'];
    }

    public static function currencyCode($iso)
    {
        $iso = strtoupper($iso);
        return isset(self::CURRENCIES[$iso]) ? self::CURRENCIES[$iso] : null;
    }

    /** Decimal amount to minor units without float rounding errors. */
    public static function toMinor($amount)
    {
        return (int) round(((float) $amount) * 100);
    }

    public function register($orderNumber, $minorAmount, $currencyCode, $returnUrl, $failUrl, $description, $language, $email = null, $callbackUrl = null)
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
        return $this->call('register.do', $data);
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
