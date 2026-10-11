<?php
// Tiny stand-in for the Solosmartpay API (payments endpoints only). Run: php -S 127.0.0.1:8891 mock-api.php
$stateFile = __DIR__ . '/state.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['invoices' => [], 'idem' => [], 'log' => []];
$save = function () use (&$state, $stateFile) { file_put_contents($stateFile, json_encode($state)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
$out = function ($code, $payload) { http_response_code($code); echo json_encode($payload); exit; };

if ($path === '/_state') { $out(200, $state); }
if ($path === '/_reset') { @unlink($stateFile); $out(200, ['reset' => true]); }

$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
$state['log'][] = ['m' => $method, 'p' => $path, 'token' => $token, 'idem' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null, 'body' => $body];
if ($token !== 'ssp_test') { $save(); $out(401, ['message' => 'Invalid, expired or revoked API token.']); }

$present = fn ($i) => $i + ['checkout_url' => $i['status'] === 'pending' ? 'http://127.0.0.1:8891/pay/' . $i['id'] : null];

if ($method === 'POST' && $path === '/v1/payments') {
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) { $save(); $out(422, ['message' => 'Header Idempotency-Key wajib diisi.']); }
    if (isset($state['idem'][$key])) { $save(); $out(202, ['data' => $present($state['invoices'][$state['idem'][$key]])]); }
    foreach ($state['invoices'] as $inv) {
        if (($inv['reference'] ?? null) === ($body['reference'] ?? null) && in_array($inv['status'], ['pending', 'paid'], true) && $inv['amount'] !== $body['amount']) {
            $save(); $out(409, ['message' => 'Referensi sudah dipakai untuk tagihan lain.']);
        }
    }
    $id = sprintf('%08x-0000-4000-8000-%012x', count($state['invoices']) + 1, mt_rand());
    $state['invoices'][$id] = ['id' => $id, 'reference' => $body['reference'] ?? null, 'redirect_url' => $body['redirect_url'] ?? null, 'status' => 'pending',
        'channel' => 'bank_transfer', 'method' => $body['method'], 'amount' => $body['amount'], 'provisioning_status' => 'selection_required', 'fee' => 0];
    $state['idem'][$key] = $id;
    $save();
    $out(202, ['data' => $present($state['invoices'][$id]), 'status_url' => 'http://127.0.0.1:8891/v1/payments/' . $id]);
}
if (preg_match('#^/v1/payments/([0-9a-f-]+)$#', $path, $m)) {
    $id = $m[1];
    if (!isset($state['invoices'][$id])) { $save(); $out(404, ['message' => 'No query results']); }
    if ($method === 'GET') { $save(); $out(200, ['data' => $present($state['invoices'][$id])]); }
    if ($method === 'DELETE') {
        if (in_array($state['invoices'][$id]['status'], ['paid', 'expired'], true)) { $save(); $out(409, ['message' => 'Tagihan tidak bisa dibatalkan.', 'status' => $state['invoices'][$id]['status']]); }
        $state['invoices'][$id]['status'] = 'cancelled'; $save();
        $out(200, ['data' => $present($state['invoices'][$id])]);
    }
}
if ($path === '/_mark' && $method === 'POST') { $state['invoices'][$body['id']]['status'] = $body['status']; $save(); $out(200, ['ok' => true]); }
$save();
$out(404, ['message' => 'not found']);
