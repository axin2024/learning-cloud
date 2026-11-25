<?php
declare(strict_types=1);

$origin = $argv[1] ?? '';
$target = parse_url($origin);
$token = getenv('LEARNING_ATTENDANCE_TOKEN') ?: '';
$scheme = $target['scheme'] ?? '';
$loopback = in_array($target['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true);
$path = $target['path'] ?? '';
if (!filter_var($origin, FILTER_VALIDATE_URL) || !is_array($target)
    || !($scheme === 'https' || ($scheme === 'http' && $loopback)) || !in_array($path, ['', '/'], true)
    || array_intersect_key($target, array_flip(['user', 'pass', 'query', 'fragment'])) !== []
    || !preg_match('/\A[0-9a-f]{64}\z/', $token)) {
    fwrite(STDERR, "Usage: LEARNING_ATTENDANCE_TOKEN=<provider token> php bin/replay-attendance.php <origin> < events.jsonl\n");
    exit(2);
}

$url = rtrim($origin, '/') . '/api/live-attendance';
$lineNumber = 0;
$failed = false;
while (($line = fgets(STDIN)) !== false) {
    $lineNumber++;
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    try {
        $body = json_decode($line, false, 12, JSON_THROW_ON_ERROR);
        if (!$body instanceof stdClass || !is_string($body->event_id ?? null) || strlen($line) > 8192) {
            throw new RuntimeException('Each line must be a JSON attendance event with an event_id.');
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => ['Content-Type: application/json', 'Accept: application/json', "Authorization: Bearer $token"],
            'content' => $line, 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
        ]]);
        unset($http_response_header);
        $response = @file_get_contents($url, false, $context, 0, 65_536);
        if ($response === false || !preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $parts)) {
            throw new RuntimeException('No HTTP receipt. Replaying the same event_id is safe.');
        }
        $status = (int) $parts[1];
        $receipt = json_decode($response, false, 12, JSON_THROW_ON_ERROR);
        echo json_encode(['line' => $lineNumber, 'event_id' => $body->event_id, 'http_status' => $status, 'receipt' => $receipt], JSON_THROW_ON_ERROR), PHP_EOL;
        $failed = $failed || !in_array($status, [200, 202], true);
    } catch (JsonException | RuntimeException $error) {
        $failed = true;
        fwrite(STDERR, "Event at line $lineNumber: {$error->getMessage()}\n");
    }
}
exit($failed ? 1 : 0);
