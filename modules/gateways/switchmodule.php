<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


function switchmodule_config()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Switch Payment Gateway'],
        'accessToken' => ['FriendlyName' => 'Access Token', 'Type' => 'password', 'Size' => '64'],
        'entityId' => ['FriendlyName' => 'Entity ID (default)', 'Type' => 'text', 'Size' => '40'],
        'entityIdMada' => ['FriendlyName' => 'Entity ID (MADA, optional)', 'Type' => 'text', 'Size' => '40'],
        'brands' => ['FriendlyName' => 'Brands', 'Type' => 'text', 'Size' => '120', 'Default' => 'VISA MASTER MADA'],
        'paymentType' => ['FriendlyName' => 'Payment Type', 'Type' => 'dropdown', 'Options' => 'DB,PA', 'Default' => 'DB'],
        'mode' => ['FriendlyName' => 'Mode', 'Type' => 'dropdown', 'Options' => 'Test,Live', 'Default' => 'Test'],
    ];
}


function switchmodule_link($params)
{
    $invoiceId = $params['invoiceid'];
    $amount = number_format($params['amount'], 2, '.', '');
    $currency = $params['currency'];
    $client = $params['clientdetails'];
    $brands = trim($params['brands'] ?: 'VISA MASTER');
    $paymentType = $params['paymentType'] ?: 'DB';
    $isLive = ($params['mode'] ?? 'Test') === 'Live';

    $baseUrl = $isLive ? 'https://oppwa.com' : 'https://test.oppwa.com';
    $accessToken = $params['accessToken'];
    $entityId = $params['entityId'];

    // Optional: if you must split entities by brand/country, add logic here to pick $entityIdMada for brand MADA, etc.
    // For a single-entity setup that supports all brands, the default $entityId is fine.

    // Shopper result (your callback)
    $systemUrl = rtrim($params['systemurl'], '/');
    $callback = $systemUrl . '/modules/gateways/callback/switchmodule.php?invoiceId=' . urlencode($invoiceId);

    // Prepare checkout (server-to-server)
    $payload = http_build_query([
        'entityId' => $entityId,
        'amount' => $amount,
        'currency' => $currency,
        'paymentType' => $paymentType, // DB = debit (sale), PA = preauth
        'merchantTransactionId' => (string) $invoiceId,
        'customer.email' => $client['email'] ?? '',
        'customer.givenName' => $client['firstname'] ?? '',
        'customer.surname' => $client['lastname'] ?? '',
        'customParameters[whmcs_invoice_id]' => (string) $invoiceId,
        'shopperResultUrl' => $callback, // widget will redirect/post back here
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . '/v1/checkouts',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 45,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || !$resp) {
        return '<div class="alert alert-danger">Payment init failed: ' . htmlspecialchars($err ?: 'empty response') . '</div>';
    }

    $json = json_decode($resp, true);
    if (!is_array($json) || empty($json['id'])) {
        return '<div class="alert alert-danger">Payment init error: ' . htmlspecialchars($resp) . '</div>';
    }

    $checkoutId = $json['id'];
    $widgetJs = $baseUrl . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkoutId);

    // Render widget form that posts back to our callback
    $html = '<script src="' . htmlspecialchars($widgetJs) . '"></script>';
    $html .= '<form action="' . htmlspecialchars($callback) . '" class="paymentWidgets" data-brands="' . htmlspecialchars($brands) . '"></form>';

    return $html;
}