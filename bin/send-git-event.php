<?php
declare(strict_types=1);

$endpoint = getenv('DELIVERY_CONNECTOR_ENDPOINT');
$secret = getenv('DELIVERY_CONNECTOR_SIGNING_SECRET');
$url = is_string($endpoint) ? parse_url($endpoint) : false;
$scheme = $url['scheme'] ?? '';
$host = $url['host'] ?? '';
$loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
if ($argc !== 1 || $host === '' || ($scheme !== 'https' && !($scheme === 'http' && $loopback))
    || !preg_match('~\A/api/connectors/git/[0-9a-f]{32}\z~', $url['path'] ?? '')
    || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
    || !is_string($secret) || !preg_match('/\A[0-9a-f]{64}\z/', $secret)) {
    fwrite(STDERR, "Configure DELIVERY_CONNECTOR_ENDPOINT and DELIVERY_CONNECTOR_SIGNING_SECRET, then pipe an event JSON document on stdin.\n");
    exit(2);
}

$body = stream_get_contents(STDIN, 65_537);
if ($body === false || strlen($body) > 65_536 || !str_starts_with(ltrim($body), '{')) {
    fwrite(STDERR, "Expected a JSON object between 1 and 65536 bytes.\n");
    exit(2);
}

$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-Delivery-Timestamp: {$timestamp}\r\nX-Delivery-Signature: sha256={$signature}",
    'content' => $body,
    'timeout' => 15,
    'follow_location' => 0,
    'ignore_errors' => true,
]]);
$response = @file_get_contents($endpoint, false, $context, 0, 65_537);
$status = $http_response_header[0] ?? '';
if ($response === false || strlen($response) > 65_536 || !preg_match('~\AHTTP/\S+\s+[1-5][0-9]{2}(?:\s|\z)~', $status)) {
    fwrite(STDERR, "No receipt received. Retry the same event document to preserve its event identifier.\n");
    exit(1);
}
if (!preg_match('~\AHTTP/\S+\s+2[0-9]{2}(?:\s|\z)~', $status)) {
    fwrite(STDERR, $status . PHP_EOL . $response . PHP_EOL);
    exit(1);
}
echo $response, PHP_EOL;
