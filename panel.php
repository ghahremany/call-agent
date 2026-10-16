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
require __DIR__ . '/voice-nlu.php';
require __DIR__ . '/voice-brain.php';

date_default_timezone_set('Asia/Tehran');
session_start();

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate');

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
    $ok = true;
    if ($needCaptcha) {
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
            doc_put('auth', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'user' => $u, 'ok' => 1, 'at' => date('c')]);
            header('Location: panel.php');
            exit;
        }

        $_SESSION['fails'] = ++$fails;
        $why = [];
        if (!$okUser) { $why[] = 'نام کاربری'; }
        if (!$okPass) { $why[] = 'رمز'; }
        if (!$okCode) { $why[] = 'رمز یکبارمصرف'; }
        $loginErr = 'ورود ناموفق — ' . implode(' و ', $why) . ' نادرست است.'
                  . ($fails >= $limit ? ' از این پس کپچا لازم است.' : '');
        doc_put('auth', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'user' => $u, 'ok' => 0,
            'why' => implode(',', $why), 'at' => date('c'),
        ]);
        if ($fails >= $limit) {
            $_SESSION['cap_a'] = random_int(3, 9);
            $_SESSION['cap_b'] = random_int(2, 9);
        }
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: panel.php');
    exit;
}

$authed = !empty($_SESSION['ok']);

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
    $back = static function (string $tab): void {
        header('Location: panel.php?tab=' . $tab);
        exit;
    };

    // ── وقت‌ها ──
    if (isset($_POST['add_slots'])) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $_POST['add_slots']);
        slots_add(array_filter(array_map('trim', (array) $lines)));
        $back('slots');
    }
    if (isset($_POST['gen_slots'])) {
        $n = slots_generate((int) ($_POST['gen_days'] ?? 14));
        header('Location: panel.php?tab=slots&made=' . $n);
        exit;
    }
    if (isset($_POST['free_slot'])) { slot_free((string) $_POST['free_slot']); $back('slots'); }
    if (isset($_POST['del_slot']))  { slot_delete((string) $_POST['del_slot']); $back('slots'); }

    // ── ساعت کاری ──
    if (isset($_POST['save_work'])) {
        $days = array_map('intval', (array) ($_POST['wd'] ?? []));
        $times = array_filter(array_map('trim', preg_split('/[\s,،]+/u', j_en_num((string) ($_POST['wtimes'] ?? '')))));
        vset_save([
            'work_days'  => array_values($days),
            'work_slots' => array_values($times),
            'slot_days'  => max(1, (int) ($_POST['slot_days'] ?? 14)),
        ]);
        $back('slots');
    }

    // ── دانشنامه ──
    if (isset($_POST['kb_save'])) {
        kb_save((string) $_POST['kb_text'], (string) ($_POST['kb_note'] ?? 'ویرایش دستی'));
        $back('kb');
    }
    if (isset($_POST['kb_restore'])) {
        $r = doc_get('kb', (int) $_POST['kb_restore']);
        if ($r) { kb_save((string) $r['content'], 'بازگردانی نسخهٔ ' . (int) $_POST['kb_restore']); }
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
        }
        $back('bots');
    }
    if (isset($_POST['prov_del']))    { doc_del('prov', (int) $_POST['prov_del']); $back('bots'); }
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
        }
        $back('cust');
    }
    if (isset($_POST['cust_del'])) { doc_del('cust', (int) $_POST['cust_del']); $back('cust'); }

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

/* ───────────── آزمون سلامت ───────────── */
function health(): array
{
    $out = [];
    $out[] = ['PHP', PHP_VERSION, true];
    $out[] = ['cURL', function_exists('curl_init') ? 'فعال' : 'غیرفعال', function_exists('curl_init')];
    $out[] = ['افزونهٔ openssl', extension_loaded('openssl') ? 'فعال' : 'غیرفعال', extension_loaded('openssl')];

    foreach (['sessions', 'cache', 'logs', 'data'] as $d) {
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
:root{--bg:#eef4f3;--card:#fff;--ink:#12302f;--mute:#5b7473;--line:#d7e3e1;--ok:#1b6b68;--bad:#b3413a;--warn:#b07a1e;--soft:#f4f8f7}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:Vazirmatn,Tahoma,sans-serif;padding:20px;line-height:1.85;font-size:15px}
.wrap{max-width:1150px;margin:0 auto}
h1{font-size:1.35rem;margin:0 0 2px}
h3{font-size:1rem;margin:0 0 12px}
.sub{color:var(--mute);font-size:.85rem;margin-bottom:18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.tabs a{padding:7px 14px;border-radius:10px;background:var(--card);border:1px solid var(--line);color:var(--ink);text-decoration:none;font-size:.87rem}
.tabs a.on{background:var(--ok);color:#fff;border-color:var(--ok)}
table{width:100%;border-collapse:collapse;font-size:.86rem}
th,td{text-align:right;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--mute);font-weight:600;font-size:.79rem}
tr:last-child td{border-bottom:0}
.pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.74rem}
.pill.free{background:#dff2ee;color:var(--ok)}
.pill.booked{background:#fde6e4;color:var(--bad)}
.pill.done{background:#eee;color:var(--mute)}
.pill.off{background:#f0f0f0;color:var(--mute)}
.pill.on{background:#dff2ee;color:var(--ok)}
input[type=text],input[type=password],input[type=number],textarea,select{width:100%;padding:9px;border:1px solid var(--line);border-radius:10px;font-family:inherit;font-size:.88rem;background:#fff;color:var(--ink)}
textarea{min-height:110px;line-height:1.9}
button{background:var(--ok);color:#fff;border:0;padding:9px 18px;border-radius:10px;font-family:inherit;font-size:.88rem;cursor:pointer}
button.ghost{background:transparent;color:var(--bad);border:1px solid var(--line);padding:4px 10px;font-size:.76rem}
button.mini{background:var(--soft);color:var(--ink);border:1px solid var(--line);padding:4px 10px;font-size:.76rem}
.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}
.muted{color:var(--mute);font-size:.82rem}
.login{max-width:380px;margin:60px auto}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
@media(max-width:760px){.grid2,.grid3,.grid4{grid-template-columns:1fr}}
.stat{background:var(--soft);border-radius:12px;padding:12px 14px}
.stat b{display:block;font-size:1.5rem;line-height:1.4}
.stat span{color:var(--mute);font-size:.78rem}
code{background:#f3f7f6;padding:2px 6px;border-radius:6px;font-size:.82rem;direction:ltr;display:inline-block}
.secret{background:#fff8e6;border:1px dashed var(--warn);border-radius:10px;padding:12px;font-size:1.1rem;letter-spacing:2px;direction:ltr;text-align:center;font-weight:600}
.cal{width:100%;border-collapse:collapse}
.cal th{text-align:center;font-size:.78rem;padding:6px 0}
.cal td{border:1px solid var(--line);height:76px;width:14.28%;vertical-align:top;padding:4px;font-size:.8rem}
.cal td.empty{background:var(--soft);border-color:transparent}
.cal td.today{background:#e6f4f1;box-shadow:inset 0 0 0 2px var(--ok)}
.cal td a{text-decoration:none;color:var(--ink);display:block}
.cal .d{font-weight:600}
.cal .ev{display:block;background:var(--ok);color:#fff;border-radius:6px;font-size:.66rem;padding:1px 4px;margin-top:2px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.bar{height:8px;background:var(--soft);border-radius:6px;overflow:hidden;margin-top:6px}
.bar i{display:block;height:100%;background:var(--ok)}
.bar i.warn{background:var(--warn)}
.bar i.bad{background:var(--bad)}
</style>
</head>
<body>
<div class="wrap">

<?php if ($needSetup): ?>

  <div class="card login">
    <h1>راه‌اندازی پنل</h1>
    <p class="sub">یک‌بار انجام می‌شود. بعد از این، ورود با نام کاربری + رمز + رمز یکبارمصرف گوگل است.</p>
    <?php if (!empty($setupErr)): ?><p class="bad"><?= $H($setupErr) ?></p><?php endif; ?>
    <form method="post">
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
    <form method="post">
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

  <h1><?= $H((string) vset('clinic')) ?></h1>
  <div class="sub">
    <?= count($free) ?> وقت آزاد · <?= $bookedCount ?> رزرو ·
    امروز <?= $H(j_format($ty, $tm, $td)) ?> ·
    <a href="?logout=1">خروج</a>
  </div>

  <div class="tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $H($label) ?></a>
    <?php endforeach; ?>
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
                <form method="post" style="display:inline">
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
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')">
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
      <form method="post">
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
      <form method="post" class="row">
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
      <form method="post">
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
                <form method="post" style="display:inline">
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

    <div class="card">
      <h3>دانشنامه — منشی چطور با تماس‌گیرنده حرف بزند</h3>
      <p class="muted">
        هر چیزی که منشی باید دربارهٔ کلینیک بداند اینجا بنویس: خدمات، ساعت کاری، آدرس،
        قواعد، لحن حرف زدن، کارهایی که نباید بکند. این متن به مدل داده می‌شود و
        <b>مرجع اصلی</b> است — مدل اجازه ندارد چیزی از خودش بسازد.
      </p>
      <form method="post">
        <textarea name="kb_text" style="min-height:320px" placeholder="مثال:&#10;- ساعت کاری: شنبه تا چهارشنبه، ده صبح تا هشت شب&#10;- خدمات: بوتاکس، فیلر، لیزر، مزوتراپی&#10;- آدرس: ...&#10;- قیمت‌ها را هرگز نگو؛ بگو کارشناس توضیح می‌دهد.&#10;- لحن: گرم و محاوره‌ای، بدون اصطلاح پزشکی."><?= $H($kbText) ?></textarea>
        <p><input type="text" name="kb_note" placeholder="یادداشت این نسخه (اختیاری)"></p>
        <p><button type="submit" name="kb_save" value="1">ذخیره</button></p>
      </form>
    </div>

    <div class="card">
      <h3>تاریخچهٔ نسخه‌ها</h3>
      <?php $hist = kb_history(15); ?>
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
                <form method="post" style="display:inline">
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
                <form method="post" style="display:inline">
                  <button class="mini" name="prov_test" value="<?= (int) $p['id'] ?>">آزمون</button>
                </form>
                <form method="post" style="display:inline">
                  <button class="mini" name="prov_toggle" value="<?= (int) $p['id'] ?>"><?= !empty($p['enabled']) ? 'خاموش' : 'روشن' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')">
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
      <form method="post">
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
      <form method="post" class="row">
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
      <form method="post" class="row">
        <input type="text" name="sim_text" placeholder="مثلاً: میخوام وقت رزرو کنم" style="flex:1;min-width:240px" autofocus>
        <button type="submit">بفرست</button>
      </form>
      <p class="muted" style="margin-bottom:0">
        نمونه‌ها: «میخوام وقت رزرو کنم» · «بله» · «نه» · «اسمم مریم رضایی و شمارم صفر نهصد و دوازده سیصد و چهل و پنج ششصد و هفتاد و هشت نه» · «قیمت لیزر چنده؟» · «خداحافظ»
      </p>
    </div>

  <?php /* ════════ تنظیمات ════════ */ elseif ($tab === 'set'): ?>

    <div class="card">
      <h3>متن‌های منشی</h3>
      <p class="muted">اینها همان جمله‌هایی است که منشی می‌گوید. {slot} با وقت آزاد پر می‌شود.</p>
      <form method="post">
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

<?php endif; ?>

</div>
</body>
</html>
