<?php
/**
 * امنیت و حسابرسی — برگرفته از پنل نگهبان‌یار.
 *
 *   CSRF        → هر فرم یک توکن یکتا دارد
 *   مسدودسازی  → IP متخاصم بعد از چند خطا خودکار مسدود می‌شود
 *   حسابرسی     → هر کار مهم در data/audit.jsonl ثبت می‌شود
 *   هشدار       → پیام بله برای رخدادهای مهم
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

/* ───────────── CSRF ───────────── */

function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) . '">';
}

function csrf_ok(): bool
{
    if (empty($_SESSION['csrf']) || empty($_POST['csrf'])) {
        return false;
    }
    return hash_equals((string) $_SESSION['csrf'], (string) $_POST['csrf']);
}

/* ───────────── شناسهٔ بازدیدکننده ───────────── */

function sec_ip(): string
{
    $raw = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
    $ip  = trim(explode(',', $raw)[0]);
    $ip  = (string) preg_replace('/[^0-9a-fA-F:.]/', '', $ip);
    return substr($ip, 0, 45) ?: '0.0.0.0';
}

/* ───────────── فهرست مسدودها ───────────── */

function sec_row(string $ip): ?array
{
    foreach (doc_all('sec') as $r) {
        if (($r['ip'] ?? '') === $ip) {
            return $r;
        }
    }
    return null;
}

function sec_blocked(string $ip): bool
{
    $r = sec_row($ip);
    return $r !== null && (int) ($r['blocked'] ?? 0) === 1;
}

function sec_block(string $ip, string $reason = ''): void
{
    $r = sec_row($ip);
    if ($r) {
        doc_put('sec', ['id' => (int) $r['id'], 'ip' => $ip, 'blocked' => 1,
                        'reason' => $reason !== '' ? $reason : (string) ($r['reason'] ?? ''), 'ts' => date('c')]);
    } else {
        doc_put('sec', ['ip' => $ip, 'hits' => 0, 'blocked' => 1, 'reason' => $reason, 'ts' => date('c')]);
    }
}

function sec_unblock(string $ip): void
{
    $r = sec_row($ip);
    if ($r) {
        // blocked = -1 یعنی «دستی باز شد، دیگر خودکار مسدود نکن»
        doc_put('sec', ['id' => (int) $r['id'], 'ip' => $ip, 'hits' => 0, 'blocked' => -1,
                        'reason' => 'دستی باز شد', 'ts' => date('c')]);
    }
}

/**
 * ثبت یک خطا برای IP. اگر از حد بگذرد، خودکار مسدود می‌شود.
 * خروجی true یعنی الان مسدود است.
 */
function sec_hit(string $ip, string $reason, int $threshold = 12): bool
{
    $r = sec_row($ip);
    if ($r && (int) ($r['blocked'] ?? 0) === 1) {
        return true;
    }
    if ($r && (int) ($r['blocked'] ?? 0) === -1) {
        return false;      // دستی باز شده — دیگر خودکار مسدود نکن
    }

    $hits = (int) ($r['hits'] ?? 0) + 1;
    if ($r) {
        doc_put('sec', ['id' => (int) $r['id'], 'ip' => $ip, 'hits' => $hits,
                        'blocked' => 0, 'reason' => $reason, 'ts' => date('c')]);
    } else {
        doc_put('sec', ['ip' => $ip, 'hits' => $hits, 'blocked' => 0, 'reason' => $reason, 'ts' => date('c')]);
    }

    if ($hits >= $threshold) {
        sec_block($ip, $reason);
        bale_alert('🚫 IP متخاصم مسدود شد: ' . $ip . ' — ' . $reason);
        audit('SEC_AUTOBLOCK', $ip . ' — ' . $reason);
        return true;
    }
    return false;
}

function sec_list(): array
{
    $rows = doc_all('sec');
    usort($rows, static fn($a, $b) => ((int) ($b['hits'] ?? 0)) <=> ((int) ($a['hits'] ?? 0)));
    return $rows;
}

/* ───────────── حسابرسی ───────────── */

function audit(string $act, string $detail = ''): void
{
    @file_put_contents(
        v_dir('data') . '/audit.jsonl',
        json_encode([
            'ts'     => date('c'),
            'act'    => substr($act, 0, 32),
            'detail' => v_cut($detail, 480),
            'ip'     => sec_ip(),
        ], JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/** آخرین رخدادها، تازه‌ترین اول */
function audit_list(int $limit = 200): array
{
    $f = v_dir('data') . '/audit.jsonl';
    if (!is_file($f)) {
        return [];
    }
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out   = [];
    foreach (array_reverse(array_slice($lines, -$limit)) as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) {
            $out[] = $j;
        }
    }
    return $out;
}

/** هر چند وقت یک‌بار لاگ قدیمی پاک می‌شود */
function audit_prune(int $days = 30): void
{
    if (random_int(1, 40) !== 1) {
        return;
    }
    $f = v_dir('data') . '/audit.jsonl';
    if (!is_file($f)) {
        return;
    }
    $cut   = time() - ($days * 86400);
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $keep  = [];
    foreach ($lines as $l) {
        $j = json_decode($l, true);
        if (is_array($j) && strtotime((string) ($j['ts'] ?? '')) >= $cut) {
            $keep[] = $l;
        }
    }
    if (count($keep) !== count($lines)) {
        @file_put_contents($f, implode("\n", $keep) . "\n", LOCK_EX);
    }
}

/* ───────────── هشدار بله ───────────── */

function bale_alert(string $text): void
{
    $tok  = trim((string) vset('notify_bale_token'));
    $chat = trim((string) vset('notify_bale_chat'));
    if ($tok === '' || $chat === '') {
        return;
    }
    v_http('https://tapi.bale.ai/bot' . $tok . '/sendMessage', [
        'method'  => 'POST',
        'headers' => ['Content-Type: application/json'],
        'body'    => json_encode(['chat_id' => $chat, 'text' => $text], JSON_UNESCAPED_UNICODE),
        'timeout' => 8,
    ]);
}

/* ───────────── هدرهای امنیتی ───────────── */

function sec_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(self)');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
