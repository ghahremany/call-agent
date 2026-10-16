<?php
/**
 * وقت‌های آزاد و رزروها.
 *
 * دو پشت‌بند دارد:
 *   mysql  → جدول‌های همین هاست (پیش‌فرض؛ خودکفا و سریع)
 *   sheets → گوگل‌شیت از راه یک وب‌اپ Google Apps Script (فایل apps-script.gs)
 *
 * و یک پشت‌بند بدون تنظیم:
 *   file   → یک فایل JSON روی هاست. برای شروع و آزمون، بدون نیاز به دیتابیس.
 *
 * انتخاب با 'store_driver' در voice-config.php.
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/voice-jalali.php';

/* ───────────── MySQL ───────────── */

function store_pdo(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) {
        return $pdo;
    }
    $tried = true;

    $host = (string) vcfg('db_host');
    $name = (string) vcfg('db_name');
    $user = (string) vcfg('db_user');
    $pass = (string) vcfg('db_pass');

    if ($host === '' || $name === '' || $user === '') {
        v_log('store: تنظیمات دیتابیس خالی است');
        return null;
    }

    try {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '+03:30'");   // وقت تهران
    } catch (Throwable $e) {
        v_log('store: اتصال دیتابیس نشد — ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

/** جدول‌ها را در اولین اجرا می‌سازد */
function store_ready(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $pdo = store_pdo();
    if ($pdo === null) {
        return $ok = false;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ngy_voice_slots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slot VARCHAR(48) NOT NULL,
                day VARCHAR(32) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'free',
                name VARCHAR(120) NOT NULL DEFAULT '',
                phone VARCHAR(32) NOT NULL DEFAULT '',
                sid VARCHAR(64) NOT NULL DEFAULT '',
                note VARCHAR(255) NOT NULL DEFAULT '',
                ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_slot (slot),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ngy_voice_calls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sid VARCHAR(64) NOT NULL,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                turns INT NOT NULL DEFAULT 0,
                outcome VARCHAR(24) NOT NULL DEFAULT '',
                transcript MEDIUMTEXT,
                ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sid (sid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return $ok = true;
    } catch (Throwable $e) {
        v_log('store: ساخت جدول نشد — ' . $e->getMessage());
        return $ok = false;
    }
}

/* ───────────── انتخاب پشت‌بند ───────────── */

function store_driver(): string
{
    return (string) (vcfg('store_driver') ?: 'mysql');
}

/* ───────────── انبار فایلی (بدون دیتابیس) ───────────── */

function store_file_path(): string
{
    return v_dir('data') . '/slots.json';
}

/**
 * یک تراکنش اتمیک روی فایل وقت‌ها.
 * قفل انحصاری می‌گیریم تا دو تماس هم‌زمان یک وقت را دوبار نگیرند.
 * $fn(array &$rows) — ردیف‌ها را تغییر بده؛ خروجی‌اش برگردانده می‌شود.
 */
function store_file_txn(callable $fn)
{
    $f  = store_file_path();
    $fh = @fopen($f, 'c+');
    if (!$fh) {
        v_log('store(file): فایل باز نشد');
        return null;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return null;
    }

    $raw  = (string) stream_get_contents($fh);
    $rows = json_decode($raw, true);
    if (!is_array($rows)) {
        $rows = [];
    }

    $res = $fn($rows);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $res;
}

/** فراخوانی وب‌اپ Apps Script (حالت sheets) */
function sheets_call(array $payload): ?array
{
    $url = trim((string) vcfg('sheets_url'));
    if ($url === '') {
        v_log('store: sheets_url خالی است');
        return null;
    }
    $r = v_http($url, [
        'method'  => 'POST',
        'headers' => ['Content-Type: application/json'],
        'body'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout' => 20,
    ]);
    if ($r['code'] !== 200) {
        v_log('store: sheets ' . $r['code'] . ' ' . substr($r['body'], 0, 200));
        return null;
    }
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : null;
}

/* ───────────── API عمومی ───────────── */

/** وقت‌های آزاد، به ترتیب زودترین */
function slots_free(int $limit = 6): array
{
    if (store_driver() === 'file') {
        $rows = store_file_txn(static fn(array &$r) => $r);
        $out  = [];
        foreach ((array) $rows as $r) {
            if (($r['status'] ?? '') === 'free') {
                $out[] = (string) $r['slot'];
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    if (store_driver() === 'sheets') {
        $j = sheets_call(['op' => 'free', 'limit' => $limit]);
        return is_array($j['slots'] ?? null) ? $j['slots'] : [];
    }

    if (!store_ready()) {
        return [];
    }
    try {
        $st = store_pdo()->prepare(
            "SELECT slot FROM ngy_voice_slots
             WHERE status = 'free'
             ORDER BY id ASC
             LIMIT " . max(1, min(50, $limit))
        );
        $st->execute();
        return array_map(static fn($r) => (string) $r['slot'], $st->fetchAll());
    } catch (Throwable $e) {
        v_log('slots_free: ' . $e->getMessage());
        return [];
    }
}

/** یک وقت را رزرو می‌کند. اگر کسی زودتر گرفته باشد، false برمی‌گرداند. */
function slot_take(string $slot, string $name, string $phone, string $sid): bool
{
    if (store_driver() === 'file') {
        return (bool) store_file_txn(static function (array &$rows) use ($slot, $name, $phone, $sid) {
            foreach ($rows as $i => $r) {
                if ((string) ($r['slot'] ?? '') === $slot && ($r['status'] ?? '') === 'free') {
                    $rows[$i]['status'] = 'booked';
                    $rows[$i]['name']   = $name;
                    $rows[$i]['phone']  = $phone;
                    $rows[$i]['sid']    = $sid;
                    $rows[$i]['ts']     = date('c');
                    return true;
                }
            }
            return false;
        });
    }

    if (store_driver() === 'sheets') {
        $j = sheets_call([
            'op' => 'book', 'slot' => $slot,
            'name' => $name, 'phone' => $phone, 'sid' => $sid,
        ]);
        return (bool) ($j['ok'] ?? false);
    }

    if (!store_ready()) {
        return false;
    }
    try {
        $st = store_pdo()->prepare(
            "UPDATE ngy_voice_slots
             SET status = 'booked', name = ?, phone = ?, sid = ?
             WHERE slot = ? AND status = 'free'"
        );
        $st->execute([$name, $phone, $sid, $slot]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        v_log('slot_take: ' . $e->getMessage());
        return false;
    }
}

/**
 * افزودن وقت‌های آزاد تازه (تکراری‌ها نادیده).
 * هر عضو می‌تواند رشته باشد («شنبه ۲۵ مهر ساعت ۱۰:۰۰») یا آرایه‌ای با
 * کلیدهای slot و day.
 */
function slots_add(array $slots, string $day = ''): int
{
    // یکسان‌سازی ورودی
    $rows = [];
    foreach ($slots as $s) {
        if (is_array($s)) {
            $rows[] = ['slot' => trim((string) ($s['slot'] ?? '')), 'day' => (string) ($s['day'] ?? $day)];
        } else {
            $rows[] = ['slot' => trim((string) $s), 'day' => $day];
        }
    }
    $rows = array_values(array_filter($rows, static fn($r) => $r['slot'] !== ''));

    if (store_driver() === 'file') {
        return (int) store_file_txn(static function (array &$existing) use ($rows) {
            $have = [];
            foreach ($existing as $r) {
                $have[(string) ($r['slot'] ?? '')] = true;
            }
            $n = 0;
            foreach ($rows as $r) {
                if (!isset($have[$r['slot']])) {
                    $existing[] = [
                        'slot' => $r['slot'], 'day' => $r['day'], 'status' => 'free',
                        'name' => '', 'phone' => '', 'sid' => '', 'ts' => date('c'),
                    ];
                    $have[$r['slot']] = true;
                    $n++;
                }
            }
            return $n;
        });
    }

    if (store_driver() === 'sheets') {
        $j = sheets_call(['op' => 'add', 'slots' => array_column($rows, 'slot'), 'day' => $day]);
        return (int) ($j['added'] ?? 0);
    }

    if (!store_ready()) {
        return 0;
    }
    $n = 0;
    try {
        $st = store_pdo()->prepare(
            "INSERT IGNORE INTO ngy_voice_slots (slot, day, status) VALUES (?, ?, 'free')"
        );
        foreach ($rows as $r) {
            $st->execute([$r['slot'], $r['day']]);
            $n += $st->rowCount();
        }
    } catch (Throwable $e) {
        v_log('slots_add: ' . $e->getMessage());
    }
    return $n;
}

/**
 * ساخت خودکار وقت‌ها از روی ساعت کاری.
 * مثال خروجی: «شنبه ۲۵ مهر ساعت ۱۰:۰۰» با day = «2026-10-17»
 */
function slots_generate(?int $days = null): int
{
    $days     = $days ?? (int) vcfg('slot_days');
    $workDays = array_map('intval', (array) vcfg('work_days'));
    $times    = (array) vcfg('work_slots');

    if (!$workDays || !$times) {
        return 0;
    }

    [$jy, $jm, $jd] = j_today();
    $out = [];

    for ($i = 0; $i < max(1, $days); $i++) {
        [$y, $m, $d] = j_add_days($jy, $jm, $jd, $i);
        if (!in_array(j_weekday($y, $m, $d), $workDays, true)) {
            continue;
        }
        foreach ($times as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $out[] = [
                'slot' => j_format_short($y, $m, $d) . ' ساعت ' . $t,
                'day'  => j_to_iso($y, $m, $d),
            ];
        }
    }
    return slots_add($out);
}

/** همهٔ وقت‌ها (برای پنل) */
function slots_all(): array
{
    if (store_driver() === 'file') {
        $rows = (array) store_file_txn(static fn(array &$r) => $r);
        return array_map(static fn($r) => [
            'slot'   => (string) ($r['slot'] ?? ''),
            'day'    => (string) ($r['day'] ?? ''),
            'status' => (string) ($r['status'] ?? 'free'),
            'name'   => (string) ($r['name'] ?? ''),
            'phone'  => (string) ($r['phone'] ?? ''),
            'ts'     => (string) ($r['ts'] ?? ''),
        ], $rows);
    }

    if (store_driver() === 'sheets') {
        $j = sheets_call(['op' => 'all']);
        return is_array($j['rows'] ?? null) ? $j['rows'] : [];
    }
    if (!store_ready()) {
        return [];
    }
    try {
        return store_pdo()
            ->query("SELECT slot, day, status, name, phone, ts FROM ngy_voice_slots ORDER BY id ASC")
            ->fetchAll();
    } catch (Throwable $e) {
        v_log('slots_all: ' . $e->getMessage());
        return [];
    }
}

/** اطلاعات یک وقت مشخص */
function slot_info(string $slot): ?array
{
    foreach (slots_all() as $s) {
        if ((string) ($s['slot'] ?? '') === $slot) {
            return $s;
        }
    }
    return null;
}

/** آزاد کردن یک وقت رزروشده (از پنل) */
function slot_free(string $slot): bool
{
    if (store_driver() === 'file') {
        return (bool) store_file_txn(static function (array &$rows) use ($slot) {
            foreach ($rows as $i => $r) {
                if ((string) ($r['slot'] ?? '') === $slot) {
                    $rows[$i]['status'] = 'free';
                    $rows[$i]['name']   = '';
                    $rows[$i]['phone']  = '';
                    $rows[$i]['sid']    = '';
                    return true;
                }
            }
            return false;
        });
    }
    if (store_driver() === 'sheets') {
        $j = sheets_call(['op' => 'free_slot', 'slot' => $slot]);
        return (bool) ($j['ok'] ?? false);
    }
    if (!store_ready()) {
        return false;
    }
    try {
        $st = store_pdo()->prepare(
            "UPDATE ngy_voice_slots SET status='free', name='', phone='', sid='' WHERE slot = ?"
        );
        $st->execute([$slot]);
        return $st->rowCount() >= 0;
    } catch (Throwable $e) {
        v_log('slot_free: ' . $e->getMessage());
        return false;
    }
}

/** حذف یک وقت (از پنل) */
function slot_delete(string $slot): bool
{
    if (store_driver() === 'file') {
        return (bool) store_file_txn(static function (array &$rows) use ($slot) {
            foreach ($rows as $i => $r) {
                if ((string) ($r['slot'] ?? '') === $slot) {
                    array_splice($rows, $i, 1);
                    return true;
                }
            }
            return false;
        });
    }
    if (store_driver() === 'sheets') {
        $j = sheets_call(['op' => 'del_slot', 'slot' => $slot]);
        return (bool) ($j['ok'] ?? false);
    }
    if (!store_ready()) {
        return false;
    }
    try {
        $st = store_pdo()->prepare("DELETE FROM ngy_voice_slots WHERE slot = ?");
        $st->execute([$slot]);
        return true;
    } catch (Throwable $e) {
        v_log('slot_delete: ' . $e->getMessage());
        return false;
    }
}

/** ثبت خلاصهٔ تماس (برای پنل و پیگیری) */
function call_log(string $sid, string $ip, int $turns, string $outcome, string $transcript): void
{
    if (store_driver() === 'file') {
        @file_put_contents(
            v_dir('data') . '/calls.jsonl',
            json_encode([
                'sid' => $sid, 'ip' => $ip, 'turns' => $turns,
                'outcome' => $outcome, 'transcript' => $transcript, 'ts' => date('c'),
            ], JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
        return;
    }

    if (store_driver() === 'sheets') {
        sheets_call(['op' => 'call', 'sid' => $sid, 'turns' => $turns, 'outcome' => $outcome]);
        return;
    }
    if (!store_ready()) {
        return;
    }
    try {
        $st = store_pdo()->prepare(
            "INSERT INTO ngy_voice_calls (sid, ip, turns, outcome, transcript)
             VALUES (?, ?, ?, ?, ?)"
        );
        $st->execute([$sid, $ip, $turns, $outcome, $transcript]);
    } catch (Throwable $e) {
        v_log('call_log: ' . $e->getMessage());
    }
}

/** آخرین تماس‌ها (برای پنل) */
function calls_all(int $limit = 40): array
{
    if (store_driver() === 'file') {
        $f = v_dir('data') . '/calls.jsonl';
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

    if (store_driver() === 'sheets') {
        return [];
    }
    if (!store_ready()) {
        return [];
    }
    try {
        return store_pdo()->query(
            "SELECT sid, ip, turns, outcome, transcript, ts
             FROM ngy_voice_calls ORDER BY id DESC LIMIT " . max(1, min(200, $limit))
        )->fetchAll();
    } catch (Throwable $e) {
        v_log('calls_all: ' . $e->getMessage());
        return [];
    }
}
