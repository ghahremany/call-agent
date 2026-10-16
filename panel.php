<?php
/**
 * پنل مدیریت منشی صوتی.
 *
 * ورود: نام کاربری + رمز + رمز یکبارمصرف گوگل.
 * بعد از چند خطا، کپچای «من ربات نیستم» لازم می‌شود.
 */
declare(strict_types=1);

@set_time_limit(60);
@ini_set('display_errors', '0');

define('VOICE_APP', 1);
require __DIR__ . '/voice-core.php';
require __DIR__ . '/voice-jalali.php';
require __DIR__ . '/voice-store.php';
require __DIR__ . '/voice-doc.php';
require __DIR__ . '/voice-prov.php';
require __DIR__ . '/voice-totp.php';
require __DIR__ . '/voice-sec.php';
require __DIR__ . '/voice-nlu.php';
require __DIR__ . '/voice-brain.php';

date_default_timezone_set('Asia/Tehran');
session_start();

sec_headers();
audit_prune();

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ───────────── مسدودها ───────────── */
$MY_IP = sec_ip();
if (sec_blocked($MY_IP)) {
    http_response_code(403);
    exit('دسترسی این نشانی مسدود شده است.');
}

$H = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* ───────────── پروندهٔ ورود پنل ───────────── */

function pauth(): array
{
    $f = __DIR__ . '/voice-panel-auth.php';
    return is_file($f) ? (array) require $f : [];
}

function pauth_write(string $user, string $hash, string $totp): bool
{
    $php = "<?php\n"
         . "if (!defined('VOICE_APP')) { http_response_code(403); exit('forbidden'); }\n"
         . "return [\n"
         . "    'user' => " . var_export($user, true) . ",\n"
         . "    'hash' => " . var_export($hash, true) . ",\n"
         . "    'totp' => " . var_export($totp, true) . ",\n"
         . "];\n";
    return (bool) @file_put_contents(__DIR__ . '/voice-panel-auth.php', $php, LOCK_EX);
}

$auth      = pauth();
$needSetup = ($auth['hash'] ?? '') === '' || ($auth['totp'] ?? '') === '';

/* ═══════════════════════════════════════════════
 *  راه‌اندازی اولیه
 * ═══════════════════════════════════════════════ */
$setupOut = null;
if ($needSetup && isset($_POST['su_user'], $_POST['su_pass'], $_POST['su_pass2'])) {
    $u  = trim((string) $_POST['su_user']);
    $p1 = (string) $_POST['su_pass'];
    $p2 = (string) $_POST['su_pass2'];

    if ($u === '' || strlen($p1) < 8) {
        $setupErr = 'نام کاربری لازم است و رمز باید حداقل ۸ کاراکتر باشد.';
    } elseif ($p1 !== $p2) {
        $setupErr = 'دو رمز یکسان نیستند.';
    } elseif (!pauth_write($u, password_hash($p1, PASSWORD_DEFAULT), totp_secret())) {
        $setupErr = 'نوشتن فایل voice-panel-auth.php ممکن نشد. دسترسی پوشه را چک کن.';
    } else {
        $auth      = pauth();
        $needSetup = false;
        $setupOut  = $auth['totp'];
    }
}

/* ═══════════════════════════════════════════════
 *  ورود
 * ═══════════════════════════════════════════════ */
$limit       = max(1, (int) vcfg('panel_fail_limit'));
$fails       = (int) ($_SESSION['fails'] ?? 0);
$needCaptcha = $fails >= $limit;
$loginErr    = '';

if ($needCaptcha && empty($_SESSION['cap_a'])) {
    $_SESSION['cap_a'] = random_int(3, 9);
    $_SESSION['cap_b'] = random_int(2, 9);
}

if (!$needSetup && isset($_POST['lg_user'])) {
    $ok = csrf_ok();
    if (!$ok) {
        $loginErr = 'نشست منقضی شده — صفحه را دوباره باز کن.';
    }
    if ($ok && $needCaptcha) {
        $ok = !empty($_POST['robot'])
           && (int) ($_POST['cap'] ?? -1) === ((int) $_SESSION['cap_a'] + (int) $_SESSION['cap_b']);
        if (!$ok) {
            $loginErr = 'پاسخ «من ربات نیستم» درست نبود.';
        }
    }
    if ($ok) {
        $u = trim((string) $_POST['lg_user']);
        $p = (string) ($_POST['lg_pass'] ?? '');
        $c = (string) ($_POST['lg_code'] ?? '');

        $okUser = hash_equals((string) $auth['user'], $u);
        $okPass = password_verify($p, (string) $auth['hash']);
        $okCode = totp_verify((string) $auth['totp'], $c);

        if ($okUser && $okPass && $okCode) {
            session_regenerate_id(true);
            $_SESSION['ok']    = true;
            $_SESSION['fails'] = 0;
            unset($_SESSION['cap_a'], $_SESSION['cap_b']);
            doc_put('auth', ['ip' => $MY_IP, 'user' => $u, 'ok' => 1, 'at' => date('c')]);
            audit('LOGIN', 'کاربر: ' . $u);
            header('Location: panel.php');
            exit;
        }

        $_SESSION['fails'] = ++$fails;
        sec_hit($MY_IP, 'ورود ناموفق به پنل', 10);
        audit('LOGIN_FAIL', 'کاربر: ' . $u);
        $why = [];
        if (!$okUser) { $why[] = 'نام کاربری'; }
        if (!$okPass) { $why[] = 'رمز'; }
        if (!$okCode) { $why[] = 'رمز یکبارمصرف'; }
        $loginErr = 'ورود ناموفق — ' . implode(' و ', $why) . ' نادرست است.'
                  . ($fails >= $limit ? ' از این پس کپچا لازم است.' : '');
        doc_put('auth', [
            'ip' => $MY_IP, 'user' => $u, 'ok' => 0,
            'why' => implode(',', $why), 'at' => date('c'),
        ]);
        if ($fails >= $limit) {
            $_SESSION['cap_a'] = random_int(3, 9);
            $_SESSION['cap_b'] = random_int(2, 9);
        }
    }
}

if (isset($_GET['logout'])) {
    audit('LOGOUT');
    $_SESSION = [];
    session_destroy();
    header('Location: panel.php');
    exit;
}

$authed = !empty($_SESSION['ok']);

/* ───────────── دانلود پشتیبان ───────────── */
if ($authed && isset($_GET['bkdl'])) {
    $f = v_dir('backups') . '/' . basename((string) $_GET['bkdl']);
    if (is_file($f)) {
        audit('BACKUP_DOWNLOAD', basename($f));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
    http_response_code(404);
    exit('پشتیبان پیدا نشد.');
}

/* ───────────── خروجی CSV ───────────── */
if ($authed && ($_GET['export'] ?? '') === 'cust') {
    $rows = doc_all('cust');
    usort($rows, static fn($a, $b) => strcmp((string) ($a['jday'] ?? ''), (string) ($b['jday'] ?? '')));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                       // BOM برای اکسل فارسی
    fputcsv($out, ['تاریخ شمسی', 'تاریخ میلادی', 'نام', 'شماره تماس', 'وقت', 'وضعیت', 'توضیح', 'زمان ثبت']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['jtext'] ?? $r['jday'] ?? ''),
            (string) ($r['day'] ?? ''),
            (string) ($r['name'] ?? ''),
            (string) ($r['phone'] ?? ''),
            (string) ($r['slot'] ?? ''),
            (string) ($r['status'] ?? ''),
            (string) ($r['note'] ?? ''),
            (string) ($r['at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

/* ═══════════════════════════════════════════════
 *  اقدام‌ها
 * ═══════════════════════════════════════════════ */
if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok()) {
        audit('CSRF_FAIL', 'نشست نامعتبر');
        sec_hit($MY_IP, 'CSRF نامعتبر', 10);
        http_response_code(419);
        exit('نشست منقضی شده — صفحه را دوباره باز کن.');
    }
    $back = static function (string $tab): void {
        header('Location: panel.php?tab=' . $tab);
        exit;
    };

    // ── وقت‌ها ──
    if (isset($_POST['add_slots'])) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $_POST['add_slots']);
        $n = slots_add(array_filter(array_map('trim', (array) $lines)));
        audit('SLOT_ADD', $n . ' وقت دستی');
        $back('slots');
    }
    if (isset($_POST['gen_slots'])) {
        $n = slots_generate((int) ($_POST['gen_days'] ?? 14));
        audit('SLOT_GEN', $n . ' وقت ساخته شد');
        header('Location: panel.php?tab=slots&made=' . $n);
        exit;
    }
    if (isset($_POST['free_slot'])) {
        slot_free((string) $_POST['free_slot']);
        audit('SLOT_FREE', (string) $_POST['free_slot']);
        $back('slots');
    }
    if (isset($_POST['del_slot'])) {
        slot_delete((string) $_POST['del_slot']);
        audit('SLOT_DEL', (string) $_POST['del_slot']);
        $back('slots');
    }

    // ── ساعت کاری ──
    if (isset($_POST['save_work'])) {
        $days = array_map('intval', (array) ($_POST['wd'] ?? []));
        $times = array_filter(array_map('trim', preg_split('/[\s,،]+/u', j_en_num((string) ($_POST['wtimes'] ?? '')))));
        vset_save([
            'work_days'  => array_values($days),
            'work_slots' => array_values($times),
            'slot_days'  => max(1, (int) ($_POST['slot_days'] ?? 14)),
        ]);
        audit('SET_WORK', implode(' ', array_values($times)));
        $back('slots');
    }

    // ── دانشنامه ──
    if (isset($_POST['kb_save'])) {
        $kind = kb_kind((string) ($_POST['kb_kind'] ?? 'personal'));
        kb_save($kind, (string) $_POST['kb_text'], (string) ($_POST['kb_note'] ?? 'ویرایش دستی'), [
            'title'    => trim((string) ($_POST['kb_title'] ?? '')),
            'greeting' => trim((string) ($_POST['kb_greeting'] ?? '')),
            'mode'     => ((string) ($_POST['kb_mode'] ?? 'contact')) === 'booking' ? 'booking' : 'contact',
        ]);
        audit('KB_SAVE', $kind . ' · ' . v_cut((string) ($_POST['kb_note'] ?? ''), 60));
        header('Location: panel.php?tab=kb&k=' . $kind);
        exit;
    }
    if (isset($_POST['kb_restore'])) {
        $r = doc_get('kb', (int) $_POST['kb_restore']);
        if ($r) {
            kb_save((string) ($r['kind'] ?? 'personal'), (string) $r['content'],
                    'بازگردانی نسخهٔ ' . (int) $_POST['kb_restore'], [
                        'title'    => (string) ($r['title'] ?? ''),
                        'greeting' => (string) ($r['greeting'] ?? ''),
                        'mode'     => (string) ($r['mode'] ?? 'contact'),
                    ]);
            audit('KB_RESTORE', (string) ($r['kind'] ?? '') . ' · نسخهٔ ' . (int) $_POST['kb_restore']);
        }
        $back('kb');
    }

    // ── ربات‌ها ──
    if (isset($_POST['prov_save'])) {
        $row = [
            'name'    => trim((string) $_POST['p_name']),
            'base'    => rtrim(trim((string) $_POST['p_base']), '/'),
            'model'   => trim((string) $_POST['p_model']),
            'token'   => trim((string) $_POST['p_token']),
            'prio'    => (int) ($_POST['p_prio'] ?? 100),
            'enabled' => !empty($_POST['p_enabled']) ? 1 : 0,
        ];
        if (!empty($_POST['p_id'])) {
            $old = doc_get('prov', (int) $_POST['p_id']);
            if ($old && $row['token'] === '') { $row['token'] = (string) ($old['token'] ?? ''); }
            $row['id'] = (int) $_POST['p_id'];
        }
        if ($row['name'] !== '' && $row['base'] !== '' && $row['model'] !== '') {
            doc_put('prov', $row);
            audit('PROV_SAVE', $row['name'] . ' · ' . $row['model']);
        }
        $back('bots');
    }
    if (isset($_POST['prov_del'])) {
        doc_del('prov', (int) $_POST['prov_del']);
        audit('PROV_DEL', 'ربات #' . (int) $_POST['prov_del']);
        $back('bots');
    }
    if (isset($_POST['prov_toggle'])) {
        $p = doc_get('prov', (int) $_POST['prov_toggle']);
        if ($p) { $p['enabled'] = empty($p['enabled']) ? 1 : 0; doc_put('prov', $p); }
        $back('bots');
    }
    if (isset($_POST['prov_test'])) {
        $p = doc_get('prov', (int) $_POST['prov_test']);
        if ($p) {
            $r = prov_test($p);
            prov_mark((int) $p['id'], $r['ok'], $r['text']);
            header('Location: panel.php?tab=bots&tested=' . (int) $p['id'] . '&ok=' . ($r['ok'] ? 1 : 0)
                 . '&msg=' . rawurlencode(substr($r['text'] !== '' ? $r['text'] : ('HTTP ' . $r['code']), 0, 160)));
            exit;
        }
        $back('bots');
    }

    // ── مشتریان ──
    if (isset($_POST['cust_save'])) {
        $row = [
            'name'   => trim((string) $_POST['c_name']),
            'phone'  => j_en_num(trim((string) $_POST['c_phone'])),
            'jday'   => trim((string) $_POST['c_jday']),
            'slot'   => trim((string) $_POST['c_slot']),
            'note'   => trim((string) $_POST['c_note']),
            'status' => (string) ($_POST['c_status'] ?? 'booked'),
        ];
        if (!empty($_POST['c_id'])) { $row['id'] = (int) $_POST['c_id']; }
        if ($row['name'] !== '' && $row['phone'] !== '') {
            doc_put('cust', $row);
            audit('CUST_SAVE', $row['name'] . ' · ' . $row['phone']);
        }
        $back('cust');
    }
    if (isset($_POST['cust_del'])) {
        doc_del('cust', (int) $_POST['cust_del']);
        audit('CUST_DEL', 'مشتری #' . (int) $_POST['cust_del']);
        $back('cust');
    }

    // ── تنظیمات ──
    if (isset($_POST['save_set'])) {
        $keys = ['clinic','greeting','ask_info','ask_name','ask_phone','final','goodbye',
                 'no_slots','didnt_hear','ai_down','error_say','offer','tts_voice','tts_model',
                 'notify_url','notify_text','notify_bale_token','notify_bale_chat','rate_per_hour'];
        $kv = [];
        foreach ($keys as $k) {
            if (isset($_POST['s_' . $k])) { $kv[$k] = trim((string) $_POST['s_' . $k]); }
        }
        vset_save($kv);
        audit('SET_SAVE', implode(' ', array_keys($kv)));
        $back('set');
    }

    // ── شبیه‌ساز ──
    if (isset($_POST['sim_reset'])) { $_SESSION['sim_log'] = []; $back('sim'); }
    if (isset($_POST['sim_start'])) {
        $_SESSION['sim_log'] = [];
        $r = brain_start([]);
        $_SESSION['sim_log'][] = ['heard' => '(شروع تماس)', 'say' => $r['say'],
                                  'path' => $r['path'], 'stage' => $r['state']['stage'], 'ai' => '', 'end' => false];
        $back('sim');
    }
    if (isset($_POST['sim_text'])) {
        $txt = trim((string) $_POST['sim_text']);
        if ($txt !== '') {
            $simSid = 'sim-' . substr(preg_replace('/[^A-Za-z0-9]/', '', session_id()) ?? 'x', 0, 24);
            $st = v_sess_load($simSid);
            $meta = [];
            $r = brain_turn($st, $txt, $simSid, $meta);
            v_sess_save($simSid, $r['state']);
            $_SESSION['sim_log'][] = ['heard' => $txt, 'say' => $r['say'], 'path' => $r['path'],
                                      'stage' => $r['state']['stage'], 'ai' => (string) ($meta['ai'] ?? ''), 'end' => (bool) $r['end']];
        }
        $back('sim');
    }

    // ── امنیت ──
    if (isset($_POST['sec_block'])) {
        sec_block((string) $_POST['sec_block'], 'دستی از پنل');
        audit('SEC_BLOCK', (string) $_POST['sec_block']);
        $back('sec');
    }
    if (isset($_POST['sec_unblock'])) {
        sec_unblock((string) $_POST['sec_unblock']);
        audit('SEC_UNBLOCK', (string) $_POST['sec_unblock']);
        $back('sec');
    }

    // ── پشتیبان ──
    if (isset($_POST['bk_now'])) {
        $r = panel_backup();
        audit('BACKUP', $r['ok'] ? basename((string) $r['file']) : (string) $r['err']);
        header('Location: panel.php?tab=backup&made=' . ($r['ok'] ? '1' : '0'));
        exit;
    }
    if (isset($_POST['bk_del'])) {
        $f = v_dir('backups') . '/' . basename((string) $_POST['bk_del']);
        if (is_file($f)) { @unlink($f); }
        audit('BACKUP_DEL', basename($f));
        $back('backup');
    }

    // ── پاک کردن نشست شبیه‌ساز ──
    if (isset($_POST['sim_wipe'])) {
        $simSid = 'sim-' . substr(preg_replace('/[^A-Za-z0-9]/', '', session_id()) ?? 'x', 0, 24);
        @unlink(v_dir('sessions') . '/' . $simSid . '.json');
        $_SESSION['sim_log'] = [];
        $back('sim');
    }
}

/* ═══════════════════════════════════════════════
 *  داده‌های نمایش
 * ═══════════════════════════════════════════════ */
$tab = (string) ($_GET['tab'] ?? 'dash');

$slots   = $authed ? slots_all() : [];
$free    = $authed ? slots_free(500) : [];
$custs   = $authed ? doc_all('cust') : [];
$provs   = $authed ? prov_list(false) : [];
$calls   = $authed ? calls_all(60) : [];
$authLog = $authed ? array_slice(array_reverse(doc_all('auth')), 0, 25) : [];
$kbText  = $authed ? kb_get() : '';

usort($custs, static fn($a, $b) => strcmp((string) ($a['jday'] ?? ''), (string) ($b['jday'] ?? '')));

[$ty, $tm, $td] = j_today();
$todayKey = sprintf('%04d-%02d-%02d', $ty, $tm, $td);

$cal = (string) ($_GET['cal'] ?? '');
$cy  = $ty;
$cm  = $tm;
if (preg_match('/^(\d{4})-(\d{1,2})$/', $cal, $mm)) {
    $cy = (int) $mm[1];
    $cm = max(1, min(12, (int) $mm[2]));
}
$calPrev = $cm === 1 ? sprintf('%04d-12', $cy - 1) : sprintf('%04d-%02d', $cy, $cm - 1);
$calNext = $cm === 12 ? sprintf('%04d-01', $cy + 1) : sprintf('%04d-%02d', $cy, $cm + 1);

$byDay = [];
foreach ($custs as $c) {
    $k = (string) ($c['jday'] ?? '');
    if ($k !== '') {
        $byDay[$k][] = $c;
    }
}

$selDay  = (string) ($_GET['day'] ?? '');
$dayRows = $selDay !== '' ? ($byDay[$selDay] ?? []) : [];

$bookedCount = count(array_filter($slots, static fn($s) => ($s['status'] ?? '') === 'booked'));
$todayRows   = $byDay[$todayKey] ?? [];

/* ───────────── پشتیبان‌گیری ───────────── */

/** یک ZIP از داده‌ها می‌سازد. کلیدها عمداً داخلش نیستند. */
function panel_backup(): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'err' => 'افزونهٔ ZipArchive روی این هاست نیست.'];
    }
    $dir = v_dir('backups');
    $zip = $dir . '/backup-' . date('Y-m-d-His') . '.zip';

    $z = new ZipArchive();
    if ($z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'err' => 'ساخت فایل ZIP ممکن نشد.'];
    }

    $n = 0;
    foreach ((array) glob(v_dir('data') . '/*') as $f) {
        if (is_file($f) && basename($f) !== '.htaccess') {
            $z->addFile($f, 'data/' . basename($f));
            $n++;
        }
    }

    $readme = "پشتیبان منشی صوتی — " . date('c') . "\n\n"
            . "این پشتیبان شامل وقت‌ها، مشتریان، دانشنامه، ربات‌ها و تنظیمات است.\n"
            . "کلیدهای API و رمز پنل عمداً داخلش نیستند (امنیت).\n"
            . "برای بازگردانی: محتوای پوشهٔ data/ را در همان پوشه روی هاست بگذار.\n"
            . "اگر رمز پنل را گم کردی، فایل voice-panel-auth.php را حذف کن؛\n"
            . "دفعهٔ بعد صفحهٔ راه‌اندازی می‌آید.\n";
    $z->addFromString('README.txt', $readme);

    $z->close();
    return ['ok' => true, 'file' => $zip, 'size' => (int) filesize($zip), 'count' => $n];
}

function panel_backups(): array
{
    $out = [];
    foreach ((array) glob(v_dir('backups') . '/*.zip') as $f) {
        $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'at' => date('Y-m-d H:i', (int) filemtime($f))];
    }
    usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

/* ───────────── آزمون سلامت ───────────── */
function health(): array
{
    $out = [];
    $out[] = ['PHP', PHP_VERSION, true];
    $out[] = ['cURL', function_exists('curl_init') ? 'فعال' : 'غیرفعال', function_exists('curl_init')];
    $out[] = ['افزونهٔ openssl', extension_loaded('openssl') ? 'فعال' : 'غیرفعال', extension_loaded('openssl')];

    foreach (['sessions', 'cache', 'logs', 'data', 'backups'] as $d) {
        $w = is_writable(v_dir($d));
        $out[] = ['پوشهٔ ' . $d, $w ? 'نوشتنی' : 'نوشتنی نیست!', $w];
    }

    $driver = store_driver();
    if ($driver === 'mysql') {
        $ok = store_ready();
        $out[] = ['دیتابیس MySQL', $ok ? 'متصل' : 'اتصال برقرار نشد', $ok];
    } elseif ($driver === 'file') {
        $out[] = ['انبار فایلی', count(slots_all()) . ' وقت ثبت‌شده', true];
    } else {
        $out[] = ['گوگل‌شیت', vcfg('sheets_url') !== '' ? 'آدرس تنظیم شده' : 'آدرس خالی است', (string) vcfg('sheets_url') !== ''];
    }

    $k = (string) vcfg('el_key');
    if ($k === '') {
        $out[] = ['کلید ElevenLabs', 'خالی است', false];
    } else {
        $r = v_http('https://api.elevenlabs.io/v1/user/subscription', ['headers' => ['xi-api-key: ' . $k], 'timeout' => 20]);
        $j = json_decode($r['body'], true);
        if ($r['code'] === 200) {
            $used = (int) ($j['character_count'] ?? 0);
            $lim  = (int) ($j['character_limit'] ?? 0);
            $tier = (string) ($j['tier'] ?? '');
            $pct  = $lim > 0 ? round($used / $lim * 100) : 0;
            $out[] = [
                'ElevenLabs',
                sprintf('%s · %s کاراکتر از %s (%d%%)', $tier, j_fa_num($used), j_fa_num($lim), $pct),
                $pct < 90,
            ];
        } else {
            $out[] = ['ElevenLabs', 'خطا ' . $r['code'], false];
        }
    }

    foreach (prov_list(false) as $p) {
        $ok = !empty($p['enabled']);
        $out[] = [
            'ربات: ' . ($p['name'] ?? '?'),
            ($ok ? 'فعال' : 'خاموش') . ' · ' . ($p['model'] ?? '') . ' · '
              . 'موفق ' . (int) ($p['ok_count'] ?? 0) . ' / خطا ' . (int) ($p['fail_count'] ?? 0)
              . (!empty($p['last_err']) ? ' · آخرین خطا: ' . $p['last_err'] : ''),
            $ok && (int) ($p['fail_count'] ?? 0) < 5,
        ];
    }

    $out[] = ['رمز یکبارمصرف', (pauth()['totp'] ?? '') !== '' ? 'تنظیم شده' : 'تنظیم نشده!', (pauth()['totp'] ?? '') !== ''];
    $out[] = ['کپچای ورود', 'بعد از ' . (int) vcfg('panel_fail_limit') . ' خطا فعال می‌شود', true];

    return $out;
}

$tabs = [
    'dash'   => 'داشبورد',
    'cal'    => 'تقویم',
    'cust'   => 'مشتریان',
    'slots'  => 'وقت‌ها',
    'kb'     => 'دانشنامه',
    'bots'   => 'ربات‌ها',
    'calls'  => 'تماس‌ها',
    'sim'    => 'شبیه‌ساز',
    'set'    => 'تنظیمات',
    'sec'    => 'امنیت',
    'log'    => 'رخدادها',
    'backup' => 'پشتیبان',
    'health' => 'سلامت',
];
if (!isset($tabs[$tab])) {
    $tab = 'dash';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>پنل منشی صوتی</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#eef4f3; --card:#fff; --ink:#12302f; --mute:#5b7473; --line:#dbe7e5;
  --ok:#1b6b68; --ok-soft:#e3f2ef; --bad:#b3413a; --bad-soft:#fdeceb; --warn:#b07a1e;
  --side:#0f2e2c; --side-ink:#a9c9c5; --side-on:#1b6b68; --soft:#f4f8f7;
  --r:16px; --sh:0 1px 2px rgba(16,44,42,.04), 0 10px 28px rgba(16,44,42,.06);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);line-height:1.85;font-size:15px;
  font-family:Vazirmatn,Tahoma,system-ui,sans-serif;-webkit-font-smoothing:antialiased}

/* ── چیدمان ── */
.shell{display:grid;grid-template-columns:250px 1fr;min-height:100vh}
.side{background:var(--side);color:var(--side-ink);padding:22px 14px;position:sticky;top:0;
  height:100vh;overflow:auto;display:flex;flex-direction:column;gap:6px}
.brand{padding:0 10px 18px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:12px}
.brand b{display:block;color:#fff;font-size:1.05rem;font-weight:800;letter-spacing:.2px}
.brand span{font-size:.74rem;color:#7fa39f}
.nav{display:flex;flex-direction:column;gap:3px;flex:1}
.nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:11px;
  color:var(--side-ink);text-decoration:none;font-size:.88rem;transition:background .16s,color .16s}
.nav a:hover{background:rgba(255,255,255,.07);color:#fff}
.nav a.on{background:var(--side-on);color:#fff;font-weight:600}
.nav a i{font-style:normal;font-size:1rem;width:20px;text-align:center;opacity:.9}
.nav .sep{margin:10px 12px 4px;font-size:.68rem;color:#5e827e;letter-spacing:.5px}
.side-foot{border-top:1px solid rgba(255,255,255,.08);padding-top:12px;margin-top:10px}
.side-foot a{display:block;padding:9px 12px;border-radius:11px;color:#e08b84;text-decoration:none;font-size:.85rem}
.side-foot a:hover{background:rgba(255,255,255,.07)}

.main{padding:26px 30px 60px;max-width:1120px;width:100%}
.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;
  margin-bottom:22px;flex-wrap:wrap}
.topbar h1{font-size:1.35rem;margin:0 0 3px;font-weight:800}
.topbar .sub{color:var(--mute);font-size:.84rem}
.chips{display:flex;gap:8px;flex-wrap:wrap}
.chip{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:5px 13px;
  font-size:.78rem;color:var(--mute);box-shadow:var(--sh)}
.chip b{color:var(--ink)}

/* ── کارت‌ها ── */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);
  padding:20px;margin-bottom:16px;box-shadow:var(--sh)}
.card h3{font-size:1rem;margin:0 0 12px;font-weight:700}
.card > h3:first-child{margin-top:0}
h1{font-size:1.35rem;margin:0 0 2px}
h3{font-size:1rem;margin:0 0 12px}
.sub{color:var(--mute);font-size:.85rem}
.muted{color:var(--mute);font-size:.82rem}

/* ── جدول ── */
table{width:100%;border-collapse:collapse;font-size:.86rem}
th,td{text-align:right;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--mute);font-weight:600;font-size:.78rem;background:var(--soft);
  position:sticky;top:0;z-index:1}
tr:last-child td{border-bottom:0}
tbody tr:hover td{background:#fafcfc}

/* ── برچسب ── */
.pill{display:inline-block;padding:2px 11px;border-radius:20px;font-size:.74rem;font-weight:600}
.pill.free{background:var(--ok-soft);color:var(--ok)}
.pill.booked{background:var(--bad-soft);color:var(--bad)}
.pill.done{background:#eef0f0;color:var(--mute)}
.pill.off{background:#eef0f0;color:var(--mute)}
.pill.on{background:var(--ok-soft);color:var(--ok)}

/* ── فرم ── */
input[type=text],input[type=password],input[type=number],textarea,select{
  width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:11px;
  font-family:inherit;font-size:.88rem;background:#fff;color:var(--ink);
  transition:border-color .16s,box-shadow .16s}
input:focus,textarea:focus,select:focus{outline:0;border-color:var(--ok);
  box-shadow:0 0 0 3px rgba(27,107,104,.13)}
textarea{min-height:110px;line-height:1.9;resize:vertical}
label{display:block;font-size:.82rem;color:var(--mute);margin-bottom:4px}
label input,label select,label textarea{margin-top:4px;color:var(--ink)}
button{background:var(--ok);color:#fff;border:0;padding:10px 20px;border-radius:11px;
  font-family:inherit;font-size:.88rem;font-weight:600;cursor:pointer;
  transition:transform .14s,box-shadow .16s,background .16s}
button:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(27,107,104,.22)}
button.ghost{background:transparent;color:var(--bad);border:1px solid var(--line);
  padding:4px 11px;font-size:.76rem;font-weight:500;box-shadow:none}
button.ghost:hover{background:var(--bad-soft);transform:none;box-shadow:none}
button.mini{background:var(--soft);color:var(--ink);border:1px solid var(--line);
  padding:5px 12px;font-size:.78rem;font-weight:500;box-shadow:none}
button.mini:hover{background:#e9f1ef;transform:none;box-shadow:none}

.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:820px){.grid2,.grid3,.grid4{grid-template-columns:1fr}}

/* ── آمار ── */
.stat{background:var(--card);border:1px solid var(--line);border-radius:var(--r);
  padding:16px;box-shadow:var(--sh);position:relative;overflow:hidden}
.stat::after{content:'';position:absolute;inset:auto 0 0 0;height:3px;background:var(--ok);opacity:.5}
.stat b{display:block;font-size:1.7rem;line-height:1.35;font-weight:800}
.stat span{color:var(--mute);font-size:.78rem}
.stat i{position:absolute;top:14px;left:14px;font-style:normal;font-size:1.1rem;opacity:.35}

code{background:var(--soft);padding:2px 7px;border-radius:7px;font-size:.82rem;
  direction:ltr;display:inline-block;font-family:ui-monospace,Menlo,monospace}
.secret{background:#fff8e6;border:1px dashed var(--warn);border-radius:12px;padding:14px;
  font-size:1.15rem;letter-spacing:2px;direction:ltr;text-align:center;font-weight:700}

/* ── تقویم ── */
.cal{width:100%;border-collapse:separate;border-spacing:0}
.cal th{text-align:center;font-size:.76rem;padding:8px 0;background:transparent;position:static}
.cal td{border:1px solid var(--line);height:82px;width:14.28%;vertical-align:top;padding:5px;font-size:.8rem}
.cal td.empty{background:var(--soft);border-color:transparent}
.cal td.today{background:var(--ok-soft);box-shadow:inset 0 0 0 2px var(--ok)}
.cal td a{text-decoration:none;color:var(--ink);display:block;height:100%}
.cal .d{font-weight:700;font-size:.85rem}
.cal .ev{display:block;background:var(--ok);color:#fff;border-radius:6px;font-size:.65rem;
  padding:1px 5px;margin-top:3px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.cal .ev.alt{background:var(--warn)}

.bar{height:7px;background:var(--soft);border-radius:6px;overflow:hidden;margin-top:7px}
.bar i{display:block;height:100%;background:var(--ok)}
.bar i.warn{background:var(--warn)}
.bar i.bad{background:var(--bad)}

/* ── ورود ── */
.login{max-width:390px;margin:70px auto}
.login h1{margin-bottom:4px}

/* ── موبایل ── */
@media(max-width:900px){
  .shell{grid-template-columns:1fr}
  .side{position:static;height:auto;flex-direction:column;padding:14px}
  .nav{flex-direction:row;overflow-x:auto;gap:6px;padding-bottom:4px}
  .nav a{white-space:nowrap;padding:8px 13px}
  .nav .sep{display:none}
  .brand{padding-bottom:12px;margin-bottom:8px}
  .main{padding:18px 16px 50px}
}
</style>
</head>
<body>

<?php if ($needSetup): ?>

  <div class="card login">
    <h1>راه‌اندازی پنل</h1>
    <p class="sub">یک‌بار انجام می‌شود. بعد از این، ورود با نام کاربری + رمز + رمز یکبارمصرف گوگل است.</p>
    <?php if (!empty($setupErr)): ?><p class="bad"><?= $H($setupErr) ?></p><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
      <p><input type="text" name="su_user" placeholder="نام کاربری" value="admin" required></p>
      <p><input type="password" name="su_pass" placeholder="رمز (حداقل ۸ کاراکتر)" required></p>
      <p><input type="password" name="su_pass2" placeholder="تکرار رمز" required></p>
      <p><button type="submit">ساخت پنل</button></p>
    </form>
  </div>

<?php elseif ($setupOut): ?>

  <div class="card login" style="max-width:520px">
    <h1>پنل ساخته شد ✓</h1>
    <p class="sub">این کلید را در Google Authenticator ثبت کن. <b>فقط همین یک بار نشان داده می‌شود.</b></p>
    <div class="secret"><?= $H(chunk_split($setupOut, 4, ' ')) ?></div>
    <p class="muted" style="margin-top:14px">
      Google Authenticator → دکمهٔ + → <b>Enter a setup key</b> → این کلید را وارد کن
      (نام: منشی صوتی).
    </p>
    <p class="muted">آدرس کامل برای اپ‌های دیگر:</p>
    <p><code style="word-break:break-all"><?= $H(totp_uri($setupOut, (string) ($auth['user'] ?? 'admin'), (string) vcfg('totp_issuer'))) ?></code></p>
    <p><a href="panel.php"><button type="button">رفتن به ورود</button></a></p>
  </div>

<?php elseif (!$authed): ?>

  <div class="card login">
    <h1>پنل منشی صوتی</h1>
    <p class="sub">نام کاربری، رمز، و کد ۶ رقمی برنامهٔ Authenticator.</p>
    <?php if ($loginErr !== ''): ?><p class="bad"><?= $H($loginErr) ?></p><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
      <p><input type="text" name="lg_user" placeholder="نام کاربری" value="<?= $H((string) ($auth['user'] ?? '')) ?>" autofocus required></p>
      <p><input type="password" name="lg_pass" placeholder="رمز" required></p>
      <p><input type="text" name="lg_code" placeholder="کد ۶ رقمی گوگل" inputmode="numeric" autocomplete="one-time-code" required></p>

      <?php if ($needCaptcha): ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:12px;background:var(--soft)">
          <p style="margin:0 0 8px"><b>تأیید کنید که ربات نیستید</b></p>
          <p style="margin:0 0 8px" class="muted">
            <?= j_fa_num((int) $_SESSION['cap_a']) ?> + <?= j_fa_num((int) $_SESSION['cap_b']) ?> = ?
          </p>
          <p style="margin:0 0 8px"><input type="number" name="cap" placeholder="پاسخ" required></p>
          <label style="display:flex;gap:8px;align-items:center;cursor:pointer">
            <input type="checkbox" name="robot" value="1" required style="width:auto">
            <span>من ربات نیستم</span>
          </label>
        </div>
      <?php endif; ?>

      <p><button type="submit">ورود</button></p>
    </form>
  </div>

<?php else: ?>

<div class="shell">
  <aside class="side">
    <div class="brand">
      <b><?= $H((string) vset('clinic')) ?></b>
      <span>منشی صوتی</span>
    </div>
    <nav class="nav">
      <?php
        $icons = ['dash'=>'📊','cal'=>'📅','cust'=>'👤','slots'=>'🕐','kb'=>'📖','bots'=>'🤖',
                  'calls'=>'📞','sim'=>'🧪','set'=>'⚙️','sec'=>'🛡','log'=>'📜','backup'=>'💾','health'=>'🩺'];
        $groups = [
            'اصلی'    => ['dash','cal','cust','slots'],
            'دستیار'  => ['kb','bots','calls','sim'],
            'سیستم'   => ['set','sec','log','backup','health'],
        ];
        foreach ($groups as $gname => $keys):
      ?>
        <div class="sep"><?= $H($gname) ?></div>
        <?php foreach ($keys as $k): ?>
          <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>">
            <i><?= $icons[$k] ?? '•' ?></i><?= $H($tabs[$k] ?? $k) ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="?logout=1">↩ خروج</a>
    </div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div>
        <h1><?= $H($tabs[$tab]) ?></h1>
        <div class="sub"><?= $H(j_format($ty, $tm, $td)) ?></div>
      </div>
      <div class="chips">
        <span class="chip"><b><?= j_fa_num(count($free)) ?></b> وقت آزاد</span>
        <span class="chip"><b><?= j_fa_num($bookedCount) ?></b> رزرو</span>
        <span class="chip"><b><?= j_fa_num(count($custs)) ?></b> مشتری</span>
        <span class="chip"><b><?= j_fa_num(count($todayRows)) ?></b> امروز</span>
      </div>
    </div>

  <?php /* ════════ داشبورد ════════ */ if ($tab === 'dash'): ?>

    <div class="grid4">
      <div class="stat"><b><?= j_fa_num(count($todayRows)) ?></b><span>نوبت امروز</span></div>
      <div class="stat"><b><?= j_fa_num($bookedCount) ?></b><span>کل رزروها</span></div>
      <div class="stat"><b><?= j_fa_num(count($free)) ?></b><span>وقت آزاد</span></div>
      <div class="stat"><b><?= j_fa_num(count($calls)) ?></b><span>تماس ثبت‌شده</span></div>
    </div>

    <div class="card">
      <h3>نوبت‌های امروز — <?= $H(j_format($ty, $tm, $td)) ?></h3>
      <?php if (!$todayRows): ?>
        <p class="muted">امروز نوبتی ثبت نشده.</p>
      <?php else: ?>
        <table>
          <tr><th>نام</th><th>شماره</th><th>وقت</th><th>توضیح</th></tr>
          <?php foreach ($todayRows as $c): ?>
            <tr>
              <td><?= $H($c['name'] ?? '') ?></td>
              <td><code><?= $H($c['phone'] ?? '') ?></code></td>
              <td><?= $H($c['slot'] ?? '') ?></td>
              <td class="muted"><?= $H($c['note'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>نوبت‌های پیش‌رو</h3>
      <?php
        $up = array_values(array_filter($custs, static fn($c) => ($c['jday'] ?? '') >= $todayKey));
        $up = array_slice($up, 0, 8);
      ?>
      <?php if (!$up): ?>
        <p class="muted">نوبتی در پیش نیست.</p>
      <?php else: ?>
        <table>
          <tr><th>تاریخ</th><th>نام</th><th>شماره</th><th>وقت</th><th></th></tr>
          <?php foreach ($up as $c): ?>
            <tr>
              <td><?= $H($c['jtext'] ?? ($c['jday'] ?? '')) ?></td>
              <td><?= $H($c['name'] ?? '') ?></td>
              <td><code><?= $H($c['phone'] ?? '') ?></code></td>
              <td><?= $H($c['slot'] ?? '') ?></td>
              <td><a class="muted" href="?tab=cal&day=<?= $H($c['jday'] ?? '') ?>">تقویم</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>آخرین ورودها به پنل</h3>
      <table>
        <tr><th>زمان</th><th>کاربر</th><th>نتیجه</th><th>IP</th></tr>
        <?php foreach (array_slice($authLog, 0, 8) as $a): ?>
          <tr>
            <td class="muted"><?= $H(substr((string) ($a['at'] ?? ''), 0, 19)) ?></td>
            <td><?= $H($a['user'] ?? '') ?></td>
            <td class="<?= !empty($a['ok']) ? 'ok' : 'bad' ?>">
              <?= !empty($a['ok']) ? 'موفق' : 'ناموفق — ' . $H($a['why'] ?? '') ?>
            </td>
            <td class="muted"><code><?= $H($a['ip'] ?? '') ?></code></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

  <?php /* ════════ تقویم ════════ */ elseif ($tab === 'cal'): ?>

    <div class="card">
      <div class="row" style="justify-content:space-between">
        <h3 style="margin:0"><?= $H(J_MONTHS[$cm]) ?> <?= j_fa_num($cy) ?></h3>
        <span>
          <a href="?tab=cal&cal=<?= $calPrev ?>"><button class="mini" type="button">ماه قبل</button></a>
          <a href="?tab=cal"><button class="mini" type="button">امروز</button></a>
          <a href="?tab=cal&cal=<?= $calNext ?>"><button class="mini" type="button">ماه بعد</button></a>
        </span>
      </div>
      <table class="cal">
        <tr><?php foreach (J_WEEKDAYS as $w): ?><th><?= $H($w) ?></th><?php endforeach; ?></tr>
        <?php
          $len   = j_month_len($cy, $cm);
          $first = j_weekday($cy, $cm, 1);
          $cell  = 0;
          echo '<tr>';
          for ($i = 0; $i < $first; $i++) { echo '<td class="empty"></td>'; $cell++; }
          for ($d = 1; $d <= $len; $d++) {
              if ($cell > 0 && $cell % 7 === 0) { echo '</tr><tr>'; }
              $key  = sprintf('%04d-%02d-%02d', $cy, $cm, $d);
              $rows = $byDay[$key] ?? [];
              $cls  = $key === $todayKey ? 'today' : '';
              echo '<td class="' . $cls . '"><a href="?tab=cal&cal=' . $cy . '-' . $cm . '&day=' . $key . '">';
              echo '<span class="d">' . j_fa_num($d) . '</span>';
              foreach (array_slice($rows, 0, 3) as $r) {
                  echo '<span class="ev">' . $H($r['name'] ?? '') . '</span>';
              }
              if (count($rows) > 3) { echo '<span class="ev">+' . j_fa_num(count($rows) - 3) . '</span>'; }
              echo '</a></td>';
              $cell++;
          }
          while ($cell % 7 !== 0) { echo '<td class="empty"></td>'; $cell++; }
          echo '</tr>';
        ?>
      </table>
    </div>

    <?php if ($selDay !== ''): ?>
      <div class="card">
        <h3>نوبت‌های <?= $H($selDay) ?></h3>
        <?php if (!$dayRows): ?>
          <p class="muted">این روز نوبتی ندارد.</p>
        <?php else: ?>
          <table>
            <tr><th>نام</th><th>شماره</th><th>وقت</th><th>وضعیت</th><th>توضیح</th></tr>
            <?php foreach ($dayRows as $c): ?>
              <tr>
                <td><?= $H($c['name'] ?? '') ?></td>
                <td><code><?= $H($c['phone'] ?? '') ?></code></td>
                <td><?= $H($c['slot'] ?? '') ?></td>
                <td><span class="pill booked"><?= $H($c['status'] ?? '') ?></span></td>
                <td class="muted"><?= $H($c['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php /* ════════ مشتریان ════════ */ elseif ($tab === 'cust'): ?>

    <div class="card">
      <div class="row" style="justify-content:space-between">
        <h3 style="margin:0">پروندهٔ مشتریان (<?= j_fa_num(count($custs)) ?>)</h3>
        <a href="?export=cust"><button class="mini" type="button">خروجی اکسل (CSV)</button></a>
      </div>
      <?php if (!$custs): ?>
        <p class="muted">هنوز مشتری‌ای ثبت نشده. مشتری‌ها خودکار از تماس‌ها ثبت می‌شوند، یا دستی اضافه کن.</p>
      <?php else: ?>
        <table>
          <tr><th>تاریخ</th><th>نام</th><th>شماره</th><th>وقت</th><th>وضعیت</th><th>توضیح</th><th></th></tr>
          <?php foreach ($custs as $c): ?>
            <tr>
              <td class="muted"><?= $H($c['jtext'] ?? ($c['jday'] ?? '')) ?></td>
              <td><?= $H($c['name'] ?? '') ?></td>
              <td><code><?= $H($c['phone'] ?? '') ?></code></td>
              <td><?= $H($c['slot'] ?? '') ?></td>
              <td>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <input type="hidden" name="c_id" value="<?= (int) ($c['id'] ?? 0) ?>">
                  <input type="hidden" name="c_name" value="<?= $H($c['name'] ?? '') ?>">
                  <input type="hidden" name="c_phone" value="<?= $H($c['phone'] ?? '') ?>">
                  <input type="hidden" name="c_jday" value="<?= $H($c['jday'] ?? '') ?>">
                  <input type="hidden" name="c_slot" value="<?= $H($c['slot'] ?? '') ?>">
                  <input type="hidden" name="c_note" value="<?= $H($c['note'] ?? '') ?>">
                  <input type="hidden" name="c_status" value="<?= ($c['status'] ?? '') === 'done' ? 'booked' : 'done' ?>">
                  <button class="mini" name="cust_save" value="1"><?= ($c['status'] ?? '') === 'done' ? 'برگردان' : 'انجام شد' ?></button>
                </form>
              </td>
              <td class="muted"><?= $H($c['note'] ?? '') ?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')"><?= csrf_field() ?>
                  <button class="ghost" name="cust_del" value="<?= (int) ($c['id'] ?? 0) ?>">حذف</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>افزودن مشتری دستی</h3>
      <form method="post"><?= csrf_field() ?>
        <div class="grid3">
          <input type="text" name="c_name" placeholder="نام و نام خانوادگی" required>
          <input type="text" name="c_phone" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required>
          <input type="text" name="c_jday" placeholder="۱۴۰۵-۰۷-۲۵ (شمسی)">
        </div>
        <p></p>
        <div class="grid3">
          <input type="text" name="c_slot" placeholder="مثلاً ۲۵ مهر ساعت ۱۰:۰۰">
          <select name="c_status">
            <option value="booked">رزرو شده</option>
            <option value="done">انجام شده</option>
          </select>
          <input type="text" name="c_note" placeholder="توضیح">
        </div>
        <p><button type="submit" name="cust_save" value="1">افزودن</button></p>
      </form>
    </div>

  <?php /* ════════ وقت‌ها ════════ */ elseif ($tab === 'slots'): ?>

    <?php if (isset($_GET['made'])): ?>
      <div class="card ok"><?= j_fa_num((int) $_GET['made']) ?> وقت تازه ساخته شد.</div>
    <?php endif; ?>

    <div class="card">
      <h3>ساخت خودکار وقت از ساعت کاری</h3>
      <p class="muted">برای روزهای کاری، از امروز تا چند روز جلوتر، وقت ساخته می‌شود. وقت‌های تکراری نادیده گرفته می‌شوند.</p>
      <form method="post" class="row"><?= csrf_field() ?>
        <label>روزهای کاری:</label>
        <?php $wd = array_map('intval', (array) vset('work_days')); ?>
        <?php foreach (J_WEEKDAYS as $i => $w): ?>
          <label style="display:flex;gap:5px;align-items:center;font-size:.85rem">
            <input type="checkbox" name="wd[]" value="<?= $i ?>" style="width:auto" <?= in_array($i, $wd, true) ? 'checked' : '' ?>>
            <?= $H($w) ?>
          </label>
        <?php endforeach; ?>
        <p style="flex-basis:100%"></p>
        <label>ساعت‌ها:</label>
        <input type="text" name="wtimes" style="flex:1;min-width:220px"
               value="<?= $H(implode(' ', (array) vset('work_slots'))) ?>"
               placeholder="۱۰:۰۰ ۱۱:۳۰ ۱۶:۳۰">
        <label>تا چند روز:</label>
        <input type="number" name="slot_days" value="<?= (int) vset('slot_days') ?>" style="width:80px" min="1" max="90">
        <button type="submit" name="save_work" value="1">ذخیره</button>
        <button type="submit" name="gen_slots" value="1" class="mini">همین حالا بساز</button>
      </form>
    </div>

    <div class="card">
      <h3>افزودن وقت دستی</h3>
      <form method="post"><?= csrf_field() ?>
        <textarea name="add_slots" placeholder="۲۶ مهر ساعت ۱۰:۰۰&#10;۲۶ مهر ساعت ۱۶:۳۰"></textarea>
        <p><button type="submit">افزودن</button></p>
      </form>
    </div>

    <div class="card">
      <h3>همهٔ وقت‌ها (<?= j_fa_num(count($slots)) ?>)</h3>
      <?php if (!$slots): ?>
        <p class="muted">هنوز وقتی ثبت نشده. تا وقتی وقت آزاد نباشد، منشی نمی‌تواند نوبت بدهد.</p>
      <?php else: ?>
        <table>
          <tr><th>وقت</th><th>تاریخ</th><th>وضعیت</th><th>نام</th><th>شماره</th><th></th></tr>
          <?php foreach ($slots as $s): ?>
            <tr>
              <td><?= $H($s['slot'] ?? '') ?></td>
              <td class="muted"><code><?= $H($s['day'] ?? '') ?></code></td>
              <td><span class="pill <?= $H($s['status'] ?? 'free') ?>"><?= $H($s['status'] ?? '') ?></span></td>
              <td><?= $H($s['name'] ?? '') ?: '<span class="muted">—</span>' ?></td>
              <td><code><?= $H($s['phone'] ?? '') ?></code></td>
              <td>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <?php if (($s['status'] ?? '') === 'booked'): ?>
                    <button class="ghost" name="free_slot" value="<?= $H($s['slot'] ?? '') ?>">آزاد کن</button>
                  <?php else: ?>
                    <button class="ghost" name="del_slot" value="<?= $H($s['slot'] ?? '') ?>">حذف</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

  <?php /* ════════ دانشنامه ════════ */ elseif ($tab === 'kb'): ?>

    <?php
      $kinds   = kb_kinds_used();
      $curKind = kb_kind((string) ($_GET['k'] ?? 'personal'));
      $doc     = kb_doc($curKind);
    ?>

    <div class="card">
      <h3>دانشنامه‌ها</h3>
      <p class="muted">
        هر سایت می‌تواند دستیار خودش را داشته باشد. ویجت هر سایت نوع خودش را می‌فرستد،
        پس با یک نصب، چند دستیار داری.
      </p>
      <div class="row">
        <?php foreach (KB_KINDS as $k => $label): ?>
          <a href="?tab=kb&k=<?= $k ?>"><button class="<?= $curKind === $k ? '' : 'mini' ?>" type="button">
            <?= $H($label) ?><?= isset($kinds[$k]) ? ' ✓' : '' ?>
          </button></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h3><?= $H(KB_KINDS[$curKind]) ?></h3>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="kb_kind" value="<?= $H($curKind) ?>">

        <div class="grid2">
          <label>نام دستیار<input type="text" name="kb_title" value="<?= $H($doc['title']) ?>"></label>
          <label>حالت گفتگو
            <select name="kb_mode">
              <option value="contact" <?= $doc['mode'] === 'contact' ? 'selected' : '' ?>>تماس — فقط نام و شماره می‌گیرد</option>
              <option value="booking" <?= $doc['mode'] === 'booking' ? 'selected' : '' ?>>نوبت — وقت پیشنهاد می‌دهد</option>
            </select>
          </label>
        </div>
        <p></p>
        <label>پیام خوش‌آمد<input type="text" name="kb_greeting" value="<?= $H($doc['greeting']) ?>"></label>

        <p></p>
        <p class="muted" style="margin-bottom:6px">
          دانشنامه — هر چیزی که این دستیار باید بداند. <b>مرجع اصلی است</b>؛
          مدل اجازه ندارد چیزی از خودش بسازد.
        </p>
        <textarea name="kb_text" style="min-height:340px"><?= $H($doc['content']) ?></textarea>

        <p><input type="text" name="kb_note" placeholder="یادداشت این نسخه (اختیاری)"></p>
        <p><button type="submit" name="kb_save" value="1">ذخیره</button></p>
      </form>
    </div>

    <div class="card">
      <h3>تاریخچهٔ نسخه‌های «<?= $H(KB_KINDS[$curKind]) ?>»</h3>
      <?php $hist = kb_history($curKind, 15); ?>
      <?php if (!$hist): ?>
        <p class="muted">هنوز نسخه‌ای نیست.</p>
      <?php else: ?>
        <table>
          <tr><th>#</th><th>یادداشت</th><th>حجم</th><th>زمان</th><th></th></tr>
          <?php foreach ($hist as $h): ?>
            <tr>
              <td><?= j_fa_num($h['id']) ?></td>
              <td><?= $H($h['note']) ?></td>
              <td class="muted"><?= j_fa_num($h['len']) ?> کاراکتر</td>
              <td class="muted"><?= $H(substr($h['at'], 0, 19)) ?></td>
              <td>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <button class="mini" name="kb_restore" value="<?= $h['id'] ?>">بازگردان</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

  <?php /* ════════ ربات‌ها ════════ */ elseif ($tab === 'bots'): ?>

    <?php if (isset($_GET['tested'])): ?>
      <div class="card <?= (int) ($_GET['ok'] ?? 0) === 1 ? 'ok' : 'bad' ?>">
        آزمون ربات #<?= j_fa_num((int) $_GET['tested']) ?>:
        <?= (int) ($_GET['ok'] ?? 0) === 1 ? 'پاسخ داد ✓' : 'خطا داد ✗' ?>
        <?php if (!empty($_GET['msg'])): ?><br><span class="muted"><?= $H($_GET['msg']) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>ربات‌ها (مدل‌های زبانی)</h3>
      <p class="muted">
        به ترتیب <b>اولویت</b> امتحان می‌شوند. اگر یکی خطا بدهد، بعدی جواب می‌دهد —
        پس اگر یکی از کار افتاد، تماس قطع نمی‌شود.
      </p>
      <?php if (!$provs): ?>
        <p class="muted">هنوز رباتی ثبت نشده. یکی اضافه کن.</p>
      <?php else: ?>
        <table>
          <tr><th>اولویت</th><th>نام</th><th>آدرس</th><th>مدل</th><th>کلید</th><th>وضعیت</th><th>آمار</th><th></th></tr>
          <?php foreach ($provs as $p): ?>
            <tr>
              <td><?= j_fa_num((int) ($p['prio'] ?? 100)) ?></td>
              <td><?= $H($p['name'] ?? '') ?></td>
              <td class="muted"><code><?= $H($p['base'] ?? '') ?></code></td>
              <td class="muted"><code><?= $H($p['model'] ?? '') ?></code></td>
              <td class="muted"><code><?= $H(substr((string) ($p['token'] ?? ''), 0, 6)) ?>…</code></td>
              <td><span class="pill <?= !empty($p['enabled']) ? 'on' : 'off' ?>"><?= !empty($p['enabled']) ? 'فعال' : 'خاموش' ?></span></td>
              <td class="muted">
                موفق <?= j_fa_num((int) ($p['ok_count'] ?? 0)) ?> / خطا <?= j_fa_num((int) ($p['fail_count'] ?? 0)) ?>
                <?php if (!empty($p['last_err'])): ?><br><span class="bad" style="font-size:.75rem"><?= $H($p['last_err']) ?></span><?php endif; ?>
              </td>
              <td>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <button class="mini" name="prov_test" value="<?= (int) $p['id'] ?>">آزمون</button>
                </form>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <button class="mini" name="prov_toggle" value="<?= (int) $p['id'] ?>"><?= !empty($p['enabled']) ? 'خاموش' : 'روشن' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')"><?= csrf_field() ?>
                  <button class="ghost" name="prov_del" value="<?= (int) $p['id'] ?>">حذف</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>افزودن ربات</h3>
      <form method="post"><?= csrf_field() ?>
        <div class="grid4">
          <input type="text" name="p_name" placeholder="نام (مثلاً Groq)" required>
          <input type="text" name="p_base" placeholder="https://api.groq.com/openai/v1" required>
          <input type="text" name="p_model" placeholder="openai/gpt-oss-120b" required>
          <input type="text" name="p_token" placeholder="کلید API" required>
        </div>
        <p></p>
        <div class="row">
          <label>اولویت:</label>
          <input type="number" name="p_prio" value="50" style="width:90px">
          <label style="display:flex;gap:6px;align-items:center">
            <input type="checkbox" name="p_enabled" value="1" checked style="width:auto"> فعال
          </label>
          <button type="submit" name="prov_save" value="1">افزودن</button>
        </div>
      </form>
      <p class="muted" style="margin-bottom:0">
        هر درگاهی که با OpenAI سازگار باشد کار می‌کند: Groq، AvalAI، OpenRouter، Together،
        یا حتی یک سرور محلی. آدرس را تا <code>/v1</code> بده، بقیه‌اش خودکار اضافه می‌شود.
      </p>
    </div>

  <?php /* ════════ تماس‌ها ════════ */ elseif ($tab === 'calls'): ?>

    <div class="card">
      <h3>تماس‌های تمام‌شده (<?= j_fa_num(count($calls)) ?>)</h3>
      <?php if (!$calls): ?>
        <p class="muted">هنوز تماسی ثبت نشده. هر تماسی که با خداحافظی تمام شود اینجا می‌آید.</p>
      <?php else: ?>
        <table>
          <tr><th>زمان</th><th>نوبت</th><th>نتیجه</th><th>آخرین گفتگو</th><th>IP</th></tr>
          <?php foreach ($calls as $c): ?>
            <tr>
              <td class="muted"><?= $H(substr((string) ($c['ts'] ?? ''), 0, 19)) ?></td>
              <td><?= j_fa_num((int) ($c['turns'] ?? 0)) ?></td>
              <td><?= $H($c['outcome'] ?? '') ?></td>
              <td><?= $H($c['transcript'] ?? '') ?></td>
              <td class="muted"><code><?= $H($c['ip'] ?? '') ?></code></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

  <?php /* ════════ شبیه‌ساز ════════ */ elseif ($tab === 'sim'): ?>

    <?php $simLog = is_array($_SESSION['sim_log'] ?? null) ? $_SESSION['sim_log'] : []; ?>

    <div class="card">
      <h3>شبیه‌ساز گفتگو</h3>
      <p class="muted">
        بدون صدا و بدون مصرف کاراکتر ElevenLabs. آن‌چه تماس‌گیرنده می‌گوید را تایپ کن و
        ببین منشی چه جوابی می‌دهد و از کدام مسیر رفت. مسیر <b>fast</b> یعنی بدون مدل زبانی
        حل شد (سریع‌تر و ارزان‌تر). برای مسیر <b>ai</b> ربات لازم است.
      </p>
      <form method="post" class="row"><?= csrf_field() ?>
        <button type="submit" name="sim_start" value="1">شروع تماس تازه</button>
        <button type="submit" name="sim_reset" value="1" class="mini">پاک کردن نمایش</button>
        <button type="submit" name="sim_wipe" value="1" class="ghost">پاک کردن حالت گفتگو</button>
      </form>
    </div>

    <?php if ($simLog): ?>
      <div class="card">
        <table>
          <tr><th style="width:36%">تماس‌گیرنده</th><th>منشی</th><th style="width:110px">مسیر</th><th style="width:110px">مرحله</th></tr>
          <?php foreach ($simLog as $l): ?>
            <tr>
              <td><?= $H($l['heard']) ?></td>
              <td><?= $H($l['say']) ?></td>
              <td><span class="pill <?= in_array($l['path'], ['fast','start'], true) ? 'free' : 'booked' ?>">
                <?= $H($l['path']) ?><?= $l['ai'] !== '' ? ' · ' . $H($l['ai']) : '' ?></span></td>
              <td class="muted"><?= $H($l['stage']) ?><?= !empty($l['end']) ? ' · پایان' : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>

    <div class="card">
      <form method="post" class="row"><?= csrf_field() ?>
        <input type="text" name="sim_text" placeholder="مثلاً: میخوام وقت رزرو کنم" style="flex:1;min-width:240px" autofocus>
        <button type="submit">بفرست</button>
      </form>
      <p class="muted" style="margin-bottom:0">
        نمونه‌ها: «میخوام وقت رزرو کنم» · «بله» · «نه» · «اسمم مریم رضایی و شمارم صفر نهصد و دوازده سیصد و چهل و پنج ششصد و هفتاد و هشت نه» · «قیمت لیزر چنده؟» · «خداحافظ»
      </p>
    </div>

  <?php /* ════════ امنیت ════════ */ elseif ($tab === 'sec'): ?>

    <div class="card">
      <h3>وضعیت امنیتی</h3>
      <table>
        <tr><td style="width:240px">نشانی شما</td><td><code><?= $H($MY_IP) ?></code></td></tr>
        <tr><td>مسدودسازی خودکار</td><td>بعد از <?= j_fa_num(10) ?> خطا از یک نشانی</td></tr>
        <tr><td>توکن CSRF</td><td><span class="ok">فعال</span> — همهٔ <?= j_fa_num(17) ?> فرم پنل</td></tr>
        <tr><td>قفل ورود</td><td><?= j_fa_num((int) vcfg('panel_fail_limit')) ?> خطا → کپچا · <?= j_fa_num((int) vcfg('panel_lock_min')) ?> دقیقه قفل</td></tr>
        <tr><td>هدرهای امنیتی</td><td><code>X-Frame-Options</code> · <code>nosniff</code> · <code>HSTS</code> · <code>Referrer-Policy</code></td></tr>
        <tr><td>رمز یکبارمصرف</td><td><?= ($auth['totp'] ?? '') !== '' ? '<span class="ok">فعال</span>' : '<span class="bad">غیرفعال</span>' ?></td></tr>
      </table>
    </div>

    <div class="card">
      <h3>نشانی‌های مسدود</h3>
      <?php $secs = sec_list(); ?>
      <?php if (!$secs): ?>
        <p class="muted">هیچ نشانی‌ای ثبت نشده.</p>
      <?php else: ?>
        <table>
          <tr><th>نشانی</th><th>وضعیت</th><th>خطا</th><th>دلیل</th><th>آخرین</th><th></th></tr>
          <?php foreach ($secs as $s): ?>
            <tr>
              <td><code><?= $H($s['ip'] ?? '') ?></code></td>
              <td>
                <?php $b = (int) ($s['blocked'] ?? 0); ?>
                <span class="pill <?= $b === 1 ? 'booked' : ($b === -1 ? 'off' : 'free') ?>">
                  <?= $b === 1 ? 'مسدود' : ($b === -1 ? 'باز شده' : 'عادی') ?>
                </span>
              </td>
              <td><?= j_fa_num((int) ($s['hits'] ?? 0)) ?></td>
              <td class="muted"><?= $H($s['reason'] ?? '') ?></td>
              <td class="muted"><?= $H(substr((string) ($s['ts'] ?? ''), 0, 16)) ?></td>
              <td>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <?php if ($b === 1): ?>
                    <button class="mini" name="sec_unblock" value="<?= $H($s['ip'] ?? '') ?>">باز کن</button>
                  <?php else: ?>
                    <button class="ghost" name="sec_block" value="<?= $H($s['ip'] ?? '') ?>">مسدود کن</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>ورودهای ناموفق اخیر</h3>
      <?php $bad = array_slice(array_filter($authLog, static fn($a) => empty($a['ok'])), 0, 10); ?>
      <?php if (!$bad): ?>
        <p class="muted">ورود ناموفقی ثبت نشده.</p>
      <?php else: ?>
        <table>
          <tr><th>زمان</th><th>کاربر</th><th>علت</th><th>نشانی</th></tr>
          <?php foreach ($bad as $a): ?>
            <tr>
              <td class="muted"><?= $H(substr((string) ($a['at'] ?? ''), 0, 19)) ?></td>
              <td><?= $H($a['user'] ?? '') ?></td>
              <td class="bad"><?= $H($a['why'] ?? '') ?></td>
              <td class="muted"><code><?= $H($a['ip'] ?? '') ?></code></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

  <?php /* ════════ رخدادها ════════ */ elseif ($tab === 'log'): ?>

    <?php
      $cat  = (string) ($_GET['cat'] ?? 'all');
      $q    = trim((string) ($_GET['q'] ?? ''));
      $days = (int) ($_GET['days'] ?? 0);
      $all  = audit_list(400);
      $cats = ['all' => 'همه', 'auth' => 'ورود', 'sec' => 'امنیت', 'kb' => 'دانشنامه',
               'prov' => 'ربات‌ها', 'cust' => 'مشتریان', 'slot' => 'وقت‌ها',
               'backup' => 'پشتیبان', 'set' => 'تنظیمات'];
      $match = static function (array $r) use ($cat, $q, $days): bool {
          $act = (string) ($r['act'] ?? '');
          if ($cat !== 'all') {
              $ok = match ($cat) {
                  'auth'   => str_starts_with($act, 'LOGIN') || $act === 'LOGOUT',
                  'sec'    => str_starts_with($act, 'SEC') || $act === 'CSRF_FAIL',
                  'kb'     => str_starts_with($act, 'KB'),
                  'prov'   => str_starts_with($act, 'PROV'),
                  'cust'   => str_starts_with($act, 'CUST'),
                  'slot'   => str_starts_with($act, 'SLOT'),
                  'backup' => str_starts_with($act, 'BACKUP'),
                  'set'    => str_starts_with($act, 'SET'),
                  default  => true,
              };
              if (!$ok) { return false; }
          }
          if ($q !== '' && stripos($act . ' ' . (string) ($r['detail'] ?? '') . ' ' . (string) ($r['ip'] ?? ''), $q) === false) {
              return false;
          }
          if ($days > 0 && strtotime((string) ($r['ts'] ?? '')) < time() - ($days * 86400)) {
              return false;
          }
          return true;
      };
      $rows = array_values(array_filter($all, $match));
    ?>

    <div class="card">
      <h3>رخدادهای پنل (<?= j_fa_num(count($rows)) ?> از <?= j_fa_num(count($all)) ?>)</h3>
      <form method="get" class="row">
        <input type="hidden" name="tab" value="log">
        <select name="cat" style="width:auto">
          <?php foreach ($cats as $k => $label): ?>
            <option value="<?= $k ?>" <?= $cat === $k ? 'selected' : '' ?>><?= $H($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="days" style="width:auto">
          <?php foreach ([0 => 'همهٔ زمان‌ها', 1 => '۲۴ ساعت', 7 => '۷ روز', 30 => '۳۰ روز'] as $k => $label): ?>
            <option value="<?= $k ?>" <?= $days === $k ? 'selected' : '' ?>><?= $H($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= $H($q) ?>" placeholder="جست‌وجو…" style="flex:1;min-width:160px">
        <button type="submit">پالایش</button>
        <a href="?tab=log"><button class="mini" type="button">پاک کردن</button></a>
      </form>
    </div>

    <div class="card">
      <?php if (!$rows): ?>
        <p class="muted">رخدادی با این پالایه نیست.</p>
      <?php else: ?>
        <table>
          <tr><th style="width:150px">زمان</th><th style="width:150px">رخداد</th><th>جزئیات</th><th style="width:130px">نشانی</th></tr>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="muted"><?= $H(str_replace('T', ' ', substr((string) ($r['ts'] ?? ''), 0, 19))) ?></td>
              <td><code><?= $H($r['act'] ?? '') ?></code></td>
              <td><?= $H($r['detail'] ?? '') ?></td>
              <td class="muted"><code><?= $H($r['ip'] ?? '') ?></code></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

  <?php /* ════════ پشتیبان ════════ */ elseif ($tab === 'backup'): ?>

    <?php if (isset($_GET['made'])): ?>
      <div class="card <?= (int) $_GET['made'] === 1 ? 'ok' : 'bad' ?>">
        <?= (int) $_GET['made'] === 1 ? 'پشتیبان ساخته شد ✓' : 'ساخت پشتیبان ممکن نشد ✗' ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>پشتیبان‌گیری</h3>
      <p class="muted">
        یک ZIP از وقت‌ها، مشتریان، دانشنامه، ربات‌ها و تنظیمات می‌سازد.
        <b>کلیدهای API و رمز پنل عمداً داخلش نیستند</b> — اگر رمز پنل را گم کردی،
        فایل <code>voice-panel-auth.php</code> را از هاست حذف کن تا صفحهٔ راه‌اندازی برگردد.
      </p>
      <form method="post"><?= csrf_field() ?>
        <button type="submit" name="bk_now" value="1">همین حالا پشتیبان بگیر</button>
      </form>
    </div>

    <div class="card">
      <h3>پشتیبان‌های موجود</h3>
      <?php $bks = panel_backups(); ?>
      <?php if (!$bks): ?>
        <p class="muted">هنوز پشتیبانی ساخته نشده.</p>
      <?php else: ?>
        <table>
          <tr><th>نام</th><th>حجم</th><th>زمان</th><th></th></tr>
          <?php foreach ($bks as $b): ?>
            <tr>
              <td><code><?= $H($b['name']) ?></code></td>
              <td class="muted"><?= j_fa_num(number_format($b['size'] / 1024, 1)) ?> کیلوبایت</td>
              <td class="muted"><?= $H($b['at']) ?></td>
              <td>
                <a href="?bkdl=<?= rawurlencode($b['name']) ?>"><button class="mini" type="button">دانلود</button></a>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')"><?= csrf_field() ?>
                  <button class="ghost" name="bk_del" value="<?= $H($b['name']) ?>">حذف</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

  <?php /* ════════ تنظیمات ════════ */ elseif ($tab === 'set'): ?>

    <div class="card">
      <h3>متن‌های منشی</h3>
      <p class="muted">اینها همان جمله‌هایی است که منشی می‌گوید. {slot} با وقت آزاد پر می‌شود.</p>
      <form method="post"><?= csrf_field() ?>
        <div class="grid2">
          <label>نام کلینیک<input type="text" name="s_clinic" value="<?= $H(vset('clinic')) ?>"></label>
          <label>پیام خوش‌آمد<input type="text" name="s_greeting" value="<?= $H(vset('greeting')) ?>"></label>
          <label>درخواست نام و شماره<input type="text" name="s_ask_info" value="<?= $H(vset('ask_info')) ?>"></label>
          <label>فقط نام<input type="text" name="s_ask_name" value="<?= $H(vset('ask_name')) ?>"></label>
          <label>فقط شماره<input type="text" name="s_ask_phone" value="<?= $H(vset('ask_phone')) ?>"></label>
          <label>پیشنهاد وقت<input type="text" name="s_offer" value="<?= $H(vset('offer')) ?>"></label>
          <label>تأیید نهایی<input type="text" name="s_final" value="<?= $H(vset('final')) ?>"></label>
          <label>خداحافظی<input type="text" name="s_goodbye" value="<?= $H(vset('goodbye')) ?>"></label>
          <label>وقت آزاد نداریم<input type="text" name="s_no_slots" value="<?= $H(vset('no_slots')) ?>"></label>
          <label>صدا نیامد<input type="text" name="s_didnt_hear" value="<?= $H(vset('didnt_hear')) ?>"></label>
          <label>ربات در دسترس نیست<input type="text" name="s_ai_down" value="<?= $H(vset('ai_down')) ?>"></label>
          <label>خطای فنی<input type="text" name="s_error_say" value="<?= $H(vset('error_say')) ?>"></label>
        </div>
        <p></p>
        <h3>صدا و امنیت</h3>
        <div class="grid3">
          <label>شناسهٔ صدا (Voice ID)<input type="text" name="s_tts_voice" value="<?= $H(vset('tts_voice')) ?>"></label>
          <label>مدل گفتار<input type="text" name="s_tts_model" value="<?= $H(vset('tts_model')) ?>"></label>
          <label>حداکثر درخواست در ساعت (هر IP)<input type="number" name="s_rate_per_hour" value="<?= (int) vset('rate_per_hour') ?>"></label>
        </div>
        <p></p>
        <div class="grid2">
          <label>آدرس وبهوک اطلاع‌رسانی رزرو (اختیاری)<input type="text" name="s_notify_url" value="<?= $H(vset('notify_url')) ?>"></label>
          <label>متن پیام اطلاع‌رسانی<input type="text" name="s_notify_text" value="<?= $H(vset('notify_text')) ?>"></label>
          <label>توکن ربات بله/تلگرام (اختیاری)<input type="text" name="s_notify_bale_token" value="<?= $H(vset('notify_bale_token')) ?>"></label>
          <label>شناسهٔ چت برای اطلاع‌رسانی<input type="text" name="s_notify_bale_chat" value="<?= $H(vset('notify_bale_chat')) ?>"></label>
        </div>
        <p><button type="submit" name="save_set" value="1">ذخیرهٔ تنظیمات</button></p>
      </form>
      <p class="muted" style="margin-bottom:0">
        صدای پیشنهادی: <code>EXAVITQu4vr4xnSDxMaL</code> (سارا، زن) ·
        <code>cgSgspJ2msm6clMCkdW9</code> (جسیکا، زن) ·
        <code>JBFqnCBsd6RMkjVDRZzb</code> (جرج، مرد) ·
        <code>onwK4e9ZLuTAKqWW03F9</code> (دنیل، مرد).
        مدل گفتار برای فارسی باید <code>eleven_v3</code> بماند.
      </p>
    </div>

    <div class="card">
      <h3>امنیت پنل</h3>
      <table>
        <tr><td>نام کاربری</td><td><code><?= $H((string) ($auth['user'] ?? '')) ?></code></td></tr>
        <tr><td>رمز یکبارمصرف</td><td><?= ($auth['totp'] ?? '') !== '' ? '<span class="ok">فعال</span>' : '<span class="bad">تنظیم نشده</span>' ?></td></tr>
        <tr><td>کپچا</td><td>بعد از <?= j_fa_num((int) vcfg('panel_fail_limit')) ?> ورود ناموفق فعال می‌شود</td></tr>
        <tr><td>مسدود بودن فایل کلیدها</td><td><code>voice-secrets.php</code> · <code>voice-panel-auth.php</code></td></tr>
      </table>
      <p class="muted" style="margin-bottom:0">
        برای عوض کردن رمز یا کلید گوگل، فایل <code>voice-panel-auth.php</code> را از هاست حذف کن؛
        دفعهٔ بعد صفحهٔ راه‌اندازی می‌آید.
      </p>
    </div>

  <?php /* ════════ سلامت ════════ */ else: ?>

    <div class="card">
      <h3>آزمون سلامت</h3>
      <p class="muted">هر بار تازه اجرا می‌شود — بعد از هر تغییر تنظیمات یک بار بیا اینجا.</p>
      <table>
        <?php foreach (health() as [$name, $msg, $ok]): ?>
          <tr>
            <td style="width:220px"><?= $H($name) ?></td>
            <td class="<?= $ok === true ? 'ok' : ($ok === false ? 'bad' : 'warn') ?>"><?= $H($msg) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="card">
      <h3>وضعیت فعلی</h3>
      <table>
        <tr><td>انبار</td><td><code><?= $H(store_driver()) ?></code></td></tr>
        <tr><td>مدل تشخیص گفتار</td><td><code><?= $H(vset('stt_provider')) ?></code> · <code><?= $H(vset('stt_model')) ?></code></td></tr>
        <tr><td>مدل گفتار</td><td><code><?= $H(vset('tts_model')) ?></code> · صدا <code><?= $H(vset('tts_voice')) ?></code></td></tr>
        <tr><td>پشتیبان مرورگر</td><td><?= vcfg('tts_browser_fallback') ? '<span class="ok">فعال</span> — اگر ElevenLabs کار نکند، مرورگر متن را می‌خواند' : 'خاموش' ?></td></tr>
        <tr><td>ساعت کاری</td><td><?= $H(implode(' ', (array) vset('work_slots'))) ?></td></tr>
        <tr><td>زمان سرور</td><td><?= $H(j_format($ty, $tm, $td)) ?> — <?= $H(date('H:i')) ?></td></tr>
      </table>
    </div>

  <?php endif; ?>

  </main>
</div>

<?php endif; ?>

</body>
</html>
