<?php
declare(strict_types=1);

// CORS Headers humesha strict_types ke bilkul niche hone chahiye
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

/**
 * CYBRON — CyberRakshak AI proxy (via Cloudflare Worker, multilingual)
 * Browser POSTs { message }. Forwarded to a Cloudflare Worker (free, outside
 * InfinityFree) which holds the real OpenRouter key and calls the AI.
 * InfinityFree blocks direct outbound calls to AI APIs, so this two-hop
 * setup is required on free hosting.
 */

// Render aur InfinityFree dono ke hisab se safe file path detection
if (file_exists(__DIR__ . '/../includes/functions.php')) {
    require_once __DIR__ . '/../includes/functions.php';
} else if (file_exists(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php';
} else {
    // Agar functions file direct root me ho
    require_once __DIR__ . '/functions.php';
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$message = trim((string)($input['message'] ?? ''));
if ($message === '') {
    json_out(['error' => 'Empty message'], 400);
}
$message = mb_substr($message, 0, 1200);

$ch = curl_init(CYBRON_AI_WORKER_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 40,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Cybron-Key: ' . CYBRON_AI_WORKER_SECRET,
    ],
    CURLOPT_POSTFIELDS     => json_encode(['message' => $message]),
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data  = $raw !== false ? json_decode((string)$raw, true) : null;
$reply = $data['reply'] ?? null;

if ($raw === false || $code >= 400 || !$reply) {
    json_out(['error' => 'AI busy. Urgent help: call ' . CYBER_HELPLINE], 502);
}

try {
    db()->prepare('INSERT INTO ai_chat_logs (user_id, user_msg, bot_msg) VALUES (?,?,?)')
        ->execute([$_SESSION['user_id'] ?? null, $message, $reply]);
} catch (Throwable $e) { /* best-effort */ }

json_out(['reply' => $reply, 'model' => $data['model'] ?? 'cybron-ai']);
