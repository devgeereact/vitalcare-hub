<?php

declare(strict_types=1);

// The only web-exposed PHP. On the server a two-line shim at ~/vitalcare.uk/api/index.php sets
// VITALCARE_PRIVATE_ROOT and requires this file's equivalent from the private tree.
require_once __DIR__ . '/../src/Bootstrap.php';

use Vitalcare\Bootstrap;

$root = getenv('VITALCARE_PRIVATE_ROOT') ?: dirname(__DIR__, 2) . '/.local-state/private';
$name = getenv('VITALCARE_CONFIG') ?: 'prod';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Read one byte more than the limit so an oversized body is detected, not silently truncated.
$raw = $method === 'POST' ? file_get_contents('php://input', false, null, 0, 16385) : null;
// Cloudflare IP restoration is on for this account, so REMOTE_ADDR is the visitor. Forwarded headers are never trusted.
$ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';

[$status, $body, $headers] = array_pad(
    Bootstrap::api($root, $name)->handle($method, $path, is_string($auth) ? $auth : null, $raw === false ? null : $raw, $ip),
    3,
    [],
);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
foreach ($headers as $k => $v) {
    header($k . ': ' . $v);
}
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
