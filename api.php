<?php
/**
 * منشی صوتی — نقطهٔ ورود.
 *
 * ورودی (multipart/form-data):
 *   sessionId  شناسهٔ تماس
 *   event=start      → پیام خوش‌آمد
 *   audio=<file>     → یک جملهٔ کاربر
 *
 * خروجی: فایل صوتی (audio/mpeg) + هدر X-Call-Ended، یا JSON خطا.
 */
declare(strict_types=1);

@set_time_limit(55);
@ignore_user_abort(true);
@ini_set('display_errors', '0');

define('VOICE_APP', 1);
require __DIR__ . '/voice-core.php';
require __DIR__ . '/voice-jalali.php';
require __DIR__ . '/voice-store.php';
require __DIR__ . '/voice-doc.php';
require __DIR__ . '/voice-prov.php';
require __DIR__ . '/voice-nlu.php';
require __DIR__ . '/voice-brain.php';

/* ───── هدرها ───── */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Expose-Headers: X-Voice-Heard, X-Voice-Say, X-Call-Ended');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    v_out_err('method_not_allowed', 405);
}

/**
 * پاسخ را می‌گوید و برمی‌گرداند.
 * اگر ElevenLabs کار نکرد و پشتیبان مرورگر روشن باشد، متن را JSON می‌فرستیم
 * تا مرورگر خودش بخواند — تماس قطع نمی‌شود و هزینه‌ای هم ندارد.
 */
function api_speak(string $say, string $heard, bool $end, bool $cache = false): void
{
    $audio = $cache ? v_tts_cached($say) : v_tts($say);

    if ($audio === '') {
        if (vcfg('tts_browser_fallback')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('X-Voice-Heard: ' . rawurlencode($heard));
                header('X-Voice-Say: ' . rawurlencode($say));
                header('X-Call-Ended: ' . ($end ? 'true' : 'false'));
            }
            echo json_encode([
                'ok' => true, 'tts' => 'browser', 'say' => $say,
                'end' => $end, 'heard' => $heard,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        v_out_err('tts_failed', 502, $say);
    }

    if (!headers_sent()) {
        header('X-Voice-Heard: ' . rawurlencode($heard));
        header('X-Voice-Say: ' . rawurlencode($say));
        header('X-Call-Ended: ' . ($end ? 'true' : 'false'));
    }
    v_out_audio($audio);
}

/* ───── ۱) شناسهٔ تماس ───── */

$sid = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['sessionId'] ?? ''));
if (strlen($sid) < 6 || strlen($sid) > 64) {
    v_out_err('bad_session', 400);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
v_sweep();

/* ───── ۲) محدودیت نرخ ───── */

if (!v_rate_ok($ip, (int) vcfg('rate_per_hour'))) {
    v_log('rate limit ' . $ip);
    v_say_or_err('تعداد تماس‌های شما زیاد شده. لطفاً یک ساعت دیگر دوباره تلاش کنید.', 'rate');
}

$state = v_sess_load($sid);
$meta  = [];

/* ───── ۳) شروع تماس ───── */

if ((string) ($_POST['event'] ?? '') === 'start') {
    // ویجت هر سایت نوع دانشنامهٔ خودش را می‌فرستد
    $kind = (string) ($_POST['kb'] ?? 'personal');
    $r = brain_start($state, $kind);
    v_sess_save($sid, $r['state']);
    v_log('start ' . $sid . ' ' . $ip);
    api_speak($r['say'], '', false, true);   // از کش: فوری
}

/* ───── ۴) دریافت جملهٔ کاربر ───── */

if (!isset($_FILES['audio'])) {
    v_out_err('no_audio', 400);
}
$f = $_FILES['audio'];

if ((int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
    v_log('upload error ' . (int) $f['error']);
    v_say_or_err(brain_text('didnt_hear'), 'upload');
}
if ((int) $f['size'] <= 0) {
    v_say_or_err(brain_text('didnt_hear'), 'empty');
}
if ((int) $f['size'] > (int) vcfg('max_audio_mb') * 1048576) {
    v_out_err('too_large', 413);
}

// نام فایل پاک‌سازی می‌شود ولی پسوندش می‌ماند (تشخیص فرمت از پسوند انجام می‌شود)
$ext = strtolower((string) pathinfo((string) ($f['name'] ?? 'audio.webm'), PATHINFO_EXTENSION));
if (!in_array($ext, ['webm', 'mp4', 'm4a', 'mp3', 'wav', 'ogg', 'oga', 'opus', 'flac', 'aac', 'aiff'], true)) {
    $ext = 'webm';
}
$mime = (string) ($f['type'] ?? 'audio/webm');
if ($mime === '' || strpos($mime, 'audio/') !== 0) {
    $mime = 'audio/webm';
}

/* ───── ۵) گفتار → متن ───── */

$t0    = microtime(true);
$heard = v_stt((string) $f['tmp_name'], 'audio.' . $ext, $mime);
$t1    = microtime(true);

if ($heard === '') {
    v_log('stt empty ' . $sid);
    v_say_or_err(brain_text('didnt_hear'), 'stt');
}

/* ───── ۶) مغز گفتگو ───── */

$r = brain_turn($state, $heard, $sid, $meta);
$t2 = microtime(true);

$say   = (string) $r['say'];
$end   = (bool) $r['end'];
$state = $r['state'];

// تاریخچه برای نوبت‌های بعدی مدل
$hist = is_array($state['history'] ?? null) ? $state['history'] : [];
$hist[] = ['role' => 'user', 'content' => $heard];
$hist[] = ['role' => 'assistant', 'content' => $say];
$keep = 2 * (int) vcfg('history_turns');
$state['history'] = count($hist) > $keep ? array_slice($hist, -$keep) : $hist;

v_sess_save($sid, $state);

/* ───── ۷) متن → گفتار ───── */

$t3 = microtime(true);

v_log(sprintf(
    'turn %d [%s%s] stt=%dms brain=%dms  «%s» → «%s»',
    (int) $state['turn'],
    (string) $r['path'],
    isset($meta['ai']) ? '/' . $meta['ai'] : '',
    (int) (($t1 - $t0) * 1000),
    (int) (($t2 - $t1) * 1000),
    $heard,
    $say
));

// خلاصهٔ تماس برای پنل
if ($end) {
    call_log(
        $sid,
        $ip,
        (int) $state['turn'],
        (string) $r['path'],
        trim($heard . ' → ' . $say)
    );
}

api_speak($say, $heard, $end);
