<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/api/health' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(['status' => 'ok', 'mode' => 'starter', 'authConfigured' => false]);
    exit;
}
// No business routes are exposed before authentication/authorisation is implemented.
http_response_code(404);
echo json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint not implemented.']]);
