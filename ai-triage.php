<?php
/**
 * CYBRON — AI triage
 * POST { description, amount?, lang? }
 * Returns structured JSON: fraud_type, severity, priority, call_1930, steps[]
 * Used at case intake and on the landing "AI fraud check".
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}

$in    = json_decode(file_get_contents('php://input'), true) ?: [];
$desc  = trim((string)($in['description'] ?? ''));
$amt   = (float)($in['amount'] ?? 0);
$lang  = trim((string)($in['lang'] ?? 'the same language as the description'));
if ($desc === '') {
    json_out(['error' => 'Describe what happened'], 400);
}
$desc = mb_substr($desc, 0, 1500);

$system = "You are CyberRakshak AI, an Indian cyber-fraud triage expert for Cybron. "
        . "Analyse the victim's report and respond ONLY with a JSON object, no prose, no markdown. "
        . "Keys: "
        . "fraud_type (one of: upi_banking, otp, job, investment, identity, sim_swap, other), "
        . "severity (low|medium|high|critical), "
        . "priority (low|medium|high|critical), "
        . "call_1930 (boolean — true if money is at risk right now), "
        . "summary (one short sentence), "
        . "steps (array of 3-5 short action strings the victim should take now). "
        . "Write 'summary' and 'steps' in {$lang}.";

$user = "Report: {$desc}\nAmount lost (INR): {$amt}";

$res = ai_complete([
    ['role' => 'system', 'content' => $system],
    ['role' => 'user',   'content' => $user],
], ['temperature' => 0.3, 'max_tokens' => 600, 'json' => true]);

if (!$res['ok']) {
    json_out(['error' => 'AI busy. Call ' . CYBER_HELPLINE], 502);
}

$parsed = ai_parse_json($res['reply']);
if (!$parsed) {
    json_out(['error' => 'Could not analyse, please retry', 'raw' => $res['reply']], 502);
}

$parsed['model'] = $res['model'];
json_out($parsed);
