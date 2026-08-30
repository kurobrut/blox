<?php
// Visual-only Robux balance backend. No real Roblox currency is accessed.
header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = 'https://dw321.vercel.app';
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonOut($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
function cleanClient($v)
{
    $v = trim((string) $v);
    if ($v === '' || strlen($v) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/', $v))
        return '';
    return $v;
}
$client = cleanClient($_GET['client'] ?? '');
if ($client === '')
    jsonOut(['error' => 'Missing client'], 400);

$file = __DIR__ . '/visual_balances.json';
$fp = @fopen($file, 'c+');
if (!$fp)
    jsonOut(['error' => 'Cannot open balance storage. Check folder permissions.'], 500);
flock($fp, LOCK_EX);
$raw = stream_get_contents($fp);
$db = json_decode($raw ?: '{}', true);
if (!is_array($db))
    $db = [];
if (!isset($db[$client]))
    $db[$client] = '13942382';
$balance = preg_replace('/\D+/', '', (string) $db[$client]);
$balance = ltrim($balance, '0');
$db[$client] = $balance === '' ? '0' : $balance;
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($db, JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
jsonOut(['success' => true, 'visual' => true, 'balance' => $db[$client]]);
