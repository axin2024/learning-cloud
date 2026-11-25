<?php
declare(strict_types=1);

use LearningCloud\Http;

require dirname(__DIR__) . '/src/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (PHP_SAPI === 'cli-server' && in_array($path, ['/workspace.css', '/workspace.js', '/enterprise.js', '/study.js', '/coursework.js'], true)) {
    return false;
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; media-src 'self' https:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
if ($method === 'GET' && $path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(dirname(__DIR__) . '/resources/workspace.html');
    exit;
}

if (is_string($path) && ($path === '/healthz' || str_starts_with($path, '/api/'))) {
    (new Http())->handle($method, $path);
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found\n";
