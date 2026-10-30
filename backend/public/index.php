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

[$status, $body] = Bootstrap::api($root, $name)->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, is_string($auth) ? $auth : null);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
