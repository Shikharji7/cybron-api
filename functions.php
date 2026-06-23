<?php
/**
 * CYBRON — shared helpers
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/** Escape for HTML output */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** Is someone logged in? */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Get the current user row (cached per request) */
function current_user(): ?array
{
    static $user = null;
    if (!is_logged_in()) {
        return null;
    }
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

/** Redirect helper */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** Require login, else send to landing */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect(BASE_URL . '/index.php');
    }
}

/** Require a role to be chosen + active */
function require_active(): void
{
    require_login();
    $u = current_user();
    if (empty($u['role'])) {
        redirect(BASE_URL . '/auth/select-role.php');
    }
}

/** CSRF token */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    return !empty($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}

/** Human label for a role key */
function role_label(?string $role): string
{
    return ROLES[$role] ?? ucfirst((string)$role);
}

/** Generate the next case code, e.g. CRK-2026-00001 */
function next_case_code(): string
{
    $year = date('Y');
    $stmt = db()->query('SELECT COUNT(*) AS c FROM cases');
    $n = (int)($stmt->fetch()['c'] ?? 0) + 1;
    return sprintf('CRK-%s-%05d', $year, $n);
}

/** Send a JSON response and stop */
function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Is the current user an admin? */
function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

/** Require admin, else stop */
function require_admin(): void
{
    require_active();
    if (!is_admin()) {
        http_response_code(403);
        exit('Admins only.');
    }
}

/** Can the current user view/act on a case? */
function can_access_case(int $caseId): bool
{
    $u = current_user();
    if (!$u) return false;
    if (is_admin()) return true;
    $stmt = db()->prepare(
        'SELECT 1 FROM cases c
         LEFT JOIN case_participants p ON p.case_id=c.id AND p.user_id=:uid
         WHERE c.id=:cid AND (c.victim_id=:uid OR p.user_id=:uid) LIMIT 1'
    );
    $stmt->execute([':cid' => $caseId, ':uid' => $u['id']]);
    return (bool)$stmt->fetch();
}

/** Log a timeline event on a case */
function case_event(int $caseId, string $type, ?string $detail = null): void
{
    db()->prepare('INSERT INTO case_events (case_id, actor_id, event_type, detail) VALUES (?,?,?,?)')
        ->execute([$caseId, $_SESSION['user_id'] ?? null, $type, $detail]);
}

/** In-app notification (+ best-effort email) */
function notify(int $userId, string $title, string $body = '', string $link = ''): void
{
    db()->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?,?,?,?)')
        ->execute([$userId, $title, $body, $link]);
    try {
        $stmt = db()->prepare('SELECT email FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $email = $stmt->fetch()['email'] ?? '';
        if ($email && function_exists('mail')) {
            @mail($email, BRAND_NAME . ' — ' . $title, $body . "\n\n" . BASE_URL . $link,
                  'From: Cybron <no-reply@cybron.in>');
        }
    } catch (Throwable $e) { /* best-effort */ }
}

/** Append to the audit log */
function audit(string $action, ?string $detail = null): void
{
    db()->prepare('INSERT INTO audit_log (user_id, action, detail, ip) VALUES (?,?,?,?)')
        ->execute([$_SESSION['user_id'] ?? null, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? null]);
}

/** Deterministic Jitsi room URL for a case/consult */
function jitsi_url(string $key): string
{
    return 'https://meet.jit.si/cybron-' . preg_replace('/[^A-Za-z0-9]/', '', $key);
}
