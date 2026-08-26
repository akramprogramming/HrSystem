<?php

class HikConnectAPI 
{
    private $baseUrl;
    private $apiKey;
    private $apiSecret;

    public function __construct($baseUrl, $apiKey, $apiSecret) 
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->apiKey    = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    public function request($method, $uri, $bodyParams = []) 
    {
        $method    = strtoupper($method);
        $url       = $this->baseUrl . $uri;
        $nonce     = $this->generateNonce();
        $timestamp = (string) round(microtime(true) * 1000);

        $jsonBody  = !empty($bodyParams) ? json_encode($bodyParams) : '';
        $signature = $this->generateSignature($method, $uri, $timestamp, $nonce, $jsonBody);

        $headers = [
            'Content-Type: application/json; charset=UTF-8',
            'X-Ca-Key: ' . $this->apiKey,
            'X-Ca-Signature: ' . $signature,
            'X-Ca-Timestamp: ' . $timestamp,
            'X-Ca-Nonce: ' . $nonce,
            'X-Ca-Signature-Headers: X-Ca-Key,X-Ca-Nonce,X-Ca-Timestamp'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (!empty($jsonBody) && in_array($method, ['POST', 'PUT'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'data'      => json_decode($response, true)
        ];
    }

    private function generateSignature($method, $uri, $timestamp, $nonce, $body = '') 
    {
        $stringToSign = $method . "\n"
                      . "application/json; charset=UTF-8\n"
                      . "X-Ca-Key:" . $this->apiKey . "\n"
                      . "X-Ca-Nonce:" . $nonce . "\n"
                      . "X-Ca-Timestamp:" . $timestamp . "\n"
                      . $uri;

        if (!empty($body)) {
            $stringToSign .= "\n" . $body;
        }

        return base64_encode(hash_hmac('sha256', $stringToSign, $this->apiSecret, true));
    }

    private function generateNonce() 
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// ==========================================
// إعدادات الاتصال والتجارب
// ==========================================

$baseUrl   = 'https://ieu.isinforestrp.hik-connect.com';
$apiKey    = 'AbgdYTBgezGlZO09AxdZ7rZvm843vQyv';
$apiSecret = 'ضع_هنا_الـ_Secret_الخاص_بكبين_التنصيص';

$hik = new HikConnectAPI($baseUrl, $apiKey, $apiSecret);

header('Content-Type: application/json');

// قائمة المسارات المحتملة لأجهزة Hik-Connect Teams
$endpointsToTest = [
    'Option_1' => '/api/v1/open/deviceService/device/getDeviceList',
    'Option_2' => '/api/v1/device/search',
    'Option_3' => '/api/resource/v1/device/getDeviceList',
    'Option_4' => '/open/api/v1/device/list'
];

$results = [];

foreach ($endpointsToTest as $key => $uri) {
    $results[$key] = [
        'uri'    => $uri,
        'result' => $hik->request('POST', $uri, ['pageNo' => 1, 'pageSize' => 10])
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);