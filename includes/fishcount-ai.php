<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function fishcount_ai_enabled(): bool
{
    return FISHCOUNT_AI_URL !== '';
}

function fishcount_ai_analyze(string $filePath, string $mime = 'image/jpeg'): ?array
{
    if (!fishcount_ai_enabled() || !is_file($filePath)) {
        return null;
    }
    $bytes = file_get_contents($filePath);
    if ($bytes === false) {
        return null;
    }
    $payload = json_encode([
        'image_base64' => base64_encode($bytes),
        'mime' => $mime,
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 30,
        ],
    ]);
    $raw = @file_get_contents(FISHCOUNT_AI_URL, false, $ctx);
    if (!$raw) {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['ok']) || !is_array($json['data'] ?? null)) {
        return null;
    }
    return $json['data'];
}
