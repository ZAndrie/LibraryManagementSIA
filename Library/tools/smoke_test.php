<?php
/**
 * Simple smoke test for the API gateway.
 * Usage (from server machine):
 *   php tools/smoke_test.php
 *
 * This attempts to call the books stats endpoint via the gateway.
 */

$endpoint = 'http://localhost/Library/api/index.php?module=books&action=get_stats';

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "Request error: $err\n";
    exit(2);
}

echo "HTTP: $http\n";

$decoded = json_decode($response, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "JSON response:\n";
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Non-JSON response:\n";
echo $response . "\n";
exit(1);
