<?php
/**
 * CYBRON — AI core (OpenRouter)
 * One function every AI feature reuses. Tries each model in AI_MODELS
 * until one responds, so a single model being down never breaks the app.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * @param array $messages  [['role'=>'system','content'=>...], ...]
 * @param array $opts       ['models'=>[], 'temperature'=>0.6, 'json'=>false, 'max_tokens'=>800]
 * @return array            ['ok'=>bool, 'reply'=>string, 'model'=>string, 'error'=>?string]
 */
function ai_complete(array $messages, array $opts = []): array
{
    $models = $opts['models'] ?? AI_MODELS;
    $temp   = $opts['temperature'] ?? 0.6;
    $maxTok = $opts['max_tokens'] ?? 800;
    $json   = $opts['json'] ?? false;
    $lastErr = 'No model responded';

    foreach ($models as $model) {
        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temp,
            'max_tokens'  => $maxTok,
        ];
        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $ch = curl_init(AI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . OPENROUTER_API_KEY,
                'Content-Type: application/json',
                'HTTP-Referer: ' . AI_REFERER,
                'X-Title: ' . AI_TITLE,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) { $lastErr = 'Network error'; continue; }
        $data = json_decode((string)$raw, true);

        if ($code >= 400) {
            $lastErr = $data['error']['message'] ?? ('HTTP ' . $code);
            continue;
        }
        $reply = $data['choices'][0]['message']['content'] ?? '';
        if ($reply !== '') {
            return ['ok' => true, 'reply' => trim($reply), 'model' => $model, 'error' => null];
        }
    }

    return ['ok' => false, 'reply' => '', 'model' => '', 'error' => $lastErr];
}

/** Strip ```json fences and decode model JSON safely */
function ai_parse_json(string $text): ?array
{
    $clean = preg_replace('/^```(?:json)?|```$/m', '', trim($text));
    $data  = json_decode(trim((string)$clean), true);
    return is_array($data) ? $data : null;
}
