<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'switchmodul';
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

$isLive = ($gatewayParams['mode'] ?? 'Test') === 'Live';
$baseUrl = $isLive ? 'https://oppwa.com' : 'https://test.oppwa.com';
$entityId = $gatewayParams['entityId'];
$token = $gatewayParams['accessToken'];

// WHMCS passes invoiceId in query; widget may send "id" or "resourcePath".
$invoiceId = (int) ($_GET['invoiceId'] ?? $_POST['invoiceId'] ?? 0);
$checkoutId = $_GET['id'] ?? $_POST['id'] ?? null;
$resourcePath = $_GET['resourcePath'] ?? null;

if (!$invoiceId) {
    logTransaction($gatewayModuleName, $_REQUEST, 'Missing invoiceId');
    die('Bad Request');
}

// Build the status URL
if ($resourcePath) {
    // Should look like /v1/checkouts/{id}/payment or /v1/payments/{id}
    $statusUrl = rtrim($baseUrl, '/') . '/' . ltrim($resourcePath, '/');
} elseif ($checkoutId) {
    $statusUrl = $baseUrl . '/v1/checkouts/' . rawurlencode($checkoutId) . '/payment';
} else {
    logTransaction($gatewayModuleName, $_REQUEST, 'Missing id/resourcePath');
    die('Missing payment reference');
}

// Query payment status
$query = http_build_query(['entityId' => $entityId]);
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $statusUrl . '?' . $query,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 45,
]);
$resp = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

$data = json_decode($resp, true) ?: ['raw' => $resp, 'error' => $err];
$resultCode = $data['result']['code'] ?? '';
$txnId = $data['id'] ?? ($data['payment']['id'] ?? null);
$amount = $data['amount'] ?? null;
$currency = $data['currency'] ?? null;

// Validate invoice
checkCbInvoiceID($invoiceId, $gatewayModuleName); // throws/dies if bad invoice
if ($txnId) {
    checkCbTransID($txnId); // prevents duplicate capture
}

// Success per OPP patterns (000.*). See docs.
$success = preg_match('/^(000\\.000\\.|000\\.100\\.1|000\\.[36]|000\\.400\\.[12]0)/', $resultCode) === 1;

if ($err || !$success) {
    logTransaction($gatewayModuleName, ['request' => $_REQUEST, 'response' => $data], 'Failed');
    // Send user back to invoice with failure notice
    header('Location: ' . $CONFIG['SystemURL'] . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true');
    exit;
}

// Record payment
$fee = 0.00;
addInvoicePayment($invoiceId, $txnId ?: ('hp-' . time()), $amount, $fee, $gatewayModuleName);
logTransaction($gatewayModuleName, ['request' => $_REQUEST, 'response' => $data], 'Successful');

// Back to invoice
header('Location: ' . $CONFIG['SystemURL'] . '/viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true');
exit;