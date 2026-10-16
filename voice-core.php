<?php
/**
 * هستهٔ منشی صوتی — تشخیص گفتار، مغز گفتگو، گفتار.
 * این فایل را فقط api.php صدا می‌زند (از وب مسدود است).
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

/* ───────────── تنظیمات و ابزار ───────────── */

function vcfg(?string $k = null)
{
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/voice-config.php';
        $c = is_file($f) ? (array) require $f : [];
    }
    return $k === null ? $c : ($c[$k] ?? null);
}

function v_dir(string $name): string
{
    $d = __DIR__ . '/' . $name;
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    return $d;
}

/* ───────────── تنظیمات قابل ویرایش از پنل ───────────── */

/**
 * مثل vcfg، ولی پنل می‌تواند مقدار را بازنویسی کند.
 * بازنویسی‌ها در data/settings.json می‌مانند تا نیازی به دست‌زدن به فایل کد نباشد.
 */
function vset(string $k, $d = null)
{
    static $ov = null;
    if ($ov === null) {
        $f  = v_dir('data') . '/settings.json';
        $ov = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
    }
    if (array_key_exists($k, $ov) && $ov[$k] !== '' && $ov[$k] !== null) {
        return $ov[$k];
    }
    $v = vcfg($k);
    return $v !== null && $v !== '' ? $v : $d;
}

function vset_save(array $kv): void
{
    $f  = v_dir('data') . '/settings.json';
    $ov = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
    foreach ($kv as $k => $v) {
        $ov[$k] = $v;
    }
    @file_put_contents($f, (string) json_encode($ov, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/** برش متن با شمارش کاراکتر، بدون نیاز به mbstring */
function v_cut(string $s, int $len): string
{
    if ($len <= 0) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $len);
    }
    if (preg_match('/^.{0,' . $len . '}/us', $s, $m)) {
        return $m[0];
    }
    return substr($s, 0, $len);
}

function v_log(string $msg): void
{
    @file_put_contents(
        v_dir('logs') . '/voice-' . date('Y-m-d') . '.log',
        date('H:i:s') . '  ' . $msg . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/* ───────────── HTTP ───────────── */

/**
 * درخواست HTTP با cURL.
 * $o = ['method'=>'POST', 'headers'=>[...], 'body'=>string|array, 'timeout'=>int]
 * خروجی: ['code'=>int, 'body'=>string, 'type'=>string, 'err'=>string]
 */
function v_http(string $url, array $o = []): array
{
    if (!function_exists('curl_init')) {
        v_log('curl روی این سرور فعال نیست');
        return ['code' => 0, 'body' => '', 'type' => '', 'err' => 'curl unavailable'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => (int) ($o['timeout'] ?? 30),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'NegahbanYar-Voice/1.0',
    ]);
    if (($o['method'] ?? 'GET') === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body'] ?? '');
    }
    if (!empty($o['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $o['headers']);
    }
    $body = curl_exec($ch);
    $res  = [
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => $body === false ? '' : (string) $body,
        'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'err'  => curl_error($ch),
    ];
    curl_close($ch);
    return $res;
}

/**
 * بدنهٔ multipart/form-data را دستی می‌سازد.
 * چرا دستی؟ چون باید فیلدهای تکرارشونده (keyterms) را درست بفرستیم؛
 * آرایهٔ PHP به‌شکل keyterms[] می‌رود که ElevenLabs قبول نمی‌کند.
 */
function v_multipart(array $fields, array $files = []): array
{
    $b    = '----VoiceBoundary' . bin2hex(random_bytes(12));
    $body = '';

    foreach ($fields as $k => $v) {
        foreach (is_array($v) ? $v : [$v] as $one) {
            $body .= "--{$b}\r\n"
                   . "Content-Disposition: form-data; name=\"{$k}\"\r\n\r\n"
                   . $one . "\r\n";
        }
    }
    foreach ($files as $k => $f) {
        $fn = str_replace(['"', "\r", "\n"], '', (string) $f['filename']);
        $body .= "--{$b}\r\n"
               . "Content-Disposition: form-data; name=\"{$k}\"; filename=\"{$fn}\"\r\n"
               . "Content-Type: " . $f['mime'] . "\r\n\r\n"
               . $f['data'] . "\r\n";
    }
    $body .= "--{$b}--\r\n";

    return ['body' => $body, 'type' => "multipart/form-data; boundary={$b}"];
}

/* ───────────── ۱) گفتار → متن ───────────── */

/** ElevenLabs Scribe v2 */
function stt_elevenlabs(string $path, string $name, string $mime): string
{
    $key = (string) vcfg('el_key');
    if ($key === '') {
        return '';
    }
    $data = (string) file_get_contents($path);
    if ($data === '') {
        return '';
    }

    $fields = [
        'model_id'         => (string) vset('stt_model'),
        'language_code'    => (string) vset('stt_lang'),
        'tag_audio_events' => 'false',
        'diarize'          => 'false',
    ];
    $terms = (array) vcfg('stt_keyterms');
    if ($terms) {
        $fields['keyterms'] = array_values(array_filter(array_map('strval', $terms)));
    }

    $mp = v_multipart($fields, [
        'file' => ['filename' => $name, 'mime' => $mime, 'data' => $data],
    ]);

    $r = v_http('https://api.elevenlabs.io/v1/speech-to-text', [
        'method'  => 'POST',
        'headers' => [
            'xi-api-key: ' . $key,
            'Content-Type: ' . $mp['type'],
        ],
        'body'    => $mp['body'],
        'timeout' => 45,
    ]);

    if ($r['code'] !== 200) {
        v_log('STT(scribe) ' . $r['code'] . ' ' . substr($r['body'], 0, 250) . ' ' . $r['err']);
        return '';
    }
    $j = json_decode($r['body'], true);
    return trim((string) ($j['text'] ?? ''));
}

/** Groq Whisper — پشتیبان */
function stt_groq(string $path, string $name, string $mime): string
{
    $key = (string) vcfg('groq_key');
    if ($key === '') {
        return '';
    }
    $data = (string) file_get_contents($path);
    if ($data === '') {
        return '';
    }

    $fields = [
        'model'           => (string) vcfg('groq_stt_model'),
        'response_format' => 'json',
        'temperature'     => '0',
    ];
    if (vcfg('stt_lang')) {
        $fields['language'] = 'fa';
    }
    if (vcfg('groq_stt_hint')) {
        $fields['prompt'] = (string) vcfg('groq_stt_hint');
    }

    $mp = v_multipart($fields, [
        'file' => ['filename' => $name, 'mime' => $mime, 'data' => $data],
    ]);

    $r = v_http(vcfg('groq_base') . '/audio/transcriptions', [
        'method'  => 'POST',
        'headers' => [
            'Authorization: Bearer ' . $key,
            'Content-Type: ' . $mp['type'],
        ],
        'body'    => $mp['body'],
        'timeout' => 45,
    ]);

    if ($r['code'] !== 200) {
        v_log('STT(groq) ' . $r['code'] . ' ' . substr($r['body'], 0, 250) . ' ' . $r['err']);
        return '';
    }
    $j = json_decode($r['body'], true);
    return trim((string) ($j['text'] ?? ''));
}

/** تشخیص گفتار با پشتیبان‌گیری خودکار */
function v_stt(string $path, string $name, string $mime): string
{
    $order = (string) vcfg('stt_provider') === 'groq'
        ? ['groq', 'elevenlabs']
        : ['elevenlabs', 'groq'];

    foreach ($order as $p) {
        $t = $p === 'groq'
            ? stt_groq($path, $name, $mime)
            : stt_elevenlabs($path, $name, $mime);
        if ($t !== '') {
            return $t;
        }
    }
    return '';
}

/* ───────────── ۲) مغز گفتگو ───────────── */

/**
 * یک فراخوانی سازگار با OpenAI.
 * خروجی: ['code'=>int, 'text'=>string]  — text همان محتوای پاسخ مدل است
 */
function v_chat(string $base, string $key, string $model, array $messages, bool $jsonMode): array
{
    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => (float) vcfg('llm_temp'),
        'max_tokens'  => (int) vcfg('llm_max'),
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }
    // مدل‌های استدلالی (gpt-oss) تمام سقف توکن را صرف فکر کردن می‌کنند و
    // خروجی خالی می‌ماند. با reasoning_effort=low این هدر نمی‌رود.
    if (stripos($model, 'gpt-oss') !== false) {
        $payload['reasoning_effort'] = 'low';
    }

    $post = function () use ($base, $key, $payload): array {
        return v_http(rtrim($base, '/') . '/chat/completions', [
            'method'  => 'POST',
            'headers' => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            'body'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 40,
        ]);
    };

    $r = $post();

    // محدودیت نرخ (۴۲۹): چند لحظه صبر کن و یک بار دیگر بپرس.
    // Groq رایگان ۸۰۰۰ توکن در دقیقه است، پس این حالت واقعاً پیش می‌آید.
    if ($r['code'] === 429) {
        $wait = 3;
        if (preg_match('/try again in ([0-9.]+)s/i', $r['body'], $m)) {
            $wait = (int) ceil((float) $m[1]) + 1;
        }
        $wait = max(2, min(12, $wait));
        v_log('LLM 429 — صبر ' . $wait . ' ثانیه و تلاش دوباره');
        sleep($wait);
        $r = $post();
    }

    // بعضی درگاه‌ها response_format را قبول نمی‌کنند → یک بار بدون آن
    if ($jsonMode && $r['code'] === 400) {
        unset($payload['response_format']);
        $r = $post();
    }

    if ($r['code'] !== 200) {
        v_log('LLM ' . $model . ' ' . $r['code'] . ' ' . substr($r['body'], 0, 250) . ' ' . $r['err']);
        return ['code' => $r['code'], 'text' => ''];
    }

    $j = json_decode($r['body'], true);
    $c = $j['choices'][0]['message']['content'] ?? '';
    if (is_array($c)) {                       // بعضی مدل‌ها آرایهٔ بخش‌ها می‌دهند
        $c = implode(' ', array_map(static fn($x) => is_string($x) ? $x : (string) ($x['text'] ?? ''), $c));
    }
    return ['code' => 200, 'text' => trim((string) $c)];
}

/** JSON را از متن بیرون می‌کشد، حتی اگر مدل دورش حرف زده باشد */
function v_json_loose(string $s): ?array
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    $j = json_decode($s, true);
    if (is_array($j)) {
        return $j;
    }
    $a = strpos($s, '{');
    $b = strrpos($s, '}');
    if ($a !== false && $b !== false && $b > $a) {
        $j = json_decode(substr($s, $a, $b - $a + 1), true);
        if (is_array($j)) {
            return $j;
        }
    }
    return null;
}

/* ───────────── ۳) متن → گفتار ───────────── */

function v_tts(string $text): string
{
    $key = (string) vcfg('el_key');
    if ($key === '') {
        v_log('TTS: el_key خالی است');
        return '';
    }
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode((string) vset('tts_voice'))
         . '?output_format=' . rawurlencode((string) vset('tts_format'));

    $payload = [
        'text'           => $text,
        'model_id'       => (string) vset('tts_model'),
        'voice_settings' => [
            'stability'        => 0.5,
            'similarity_boost' => 0.75,
        ],
    ];
    if (vset('tts_lang')) {
        $payload['language_code'] = (string) vset('tts_lang');
    }

    $r = v_http($url, [
        'method'  => 'POST',
        'headers' => [
            'xi-api-key: ' . $key,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        'body'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout' => 50,
    ]);

    if ($r['code'] !== 200) {
        v_log('TTS ' . $r['code'] . ' ' . substr($r['body'], 0, 250) . ' ' . $r['err']);
        return '';
    }
    return $r['body'];
}

/** همان v_tts ولی روی دیسک کش می‌شود (سلام و پیام‌های ثابت فوری پخش می‌شوند) */
function v_tts_cached(string $text): string
{
    $f = v_dir('cache') . '/t-' . md5(
        vset('tts_voice') . '|' . vset('tts_model') . '|' . vset('tts_format') . '|' . $text
    ) . '.mp3';
    if (is_file($f) && filesize($f) > 500) {
        return (string) file_get_contents($f);
    }
    $a = v_tts($text);
    if ($a !== '') {
        @file_put_contents($f, $a, LOCK_EX);
    }
    return $a;
}

/* ───────────── نشست گفتگو ───────────── */

function v_sess_path(string $id): string
{
    return v_dir('sessions') . '/' . $id . '.json';
}

function v_sess_load(string $id): array
{
    $f = is_file(v_sess_path($id)) ? (string) file_get_contents(v_sess_path($id)) : '';
    $d = $f !== '' ? json_decode($f, true) : null;
    if (!is_array($d)) {
        $d = [];
    }
    return array_merge([
        'stage'      => 'idle',
        'slot'       => '',
        'name'       => '',
        'phone'      => '',
        'proposed'   => [],
        'turn'       => 0,
        'history'    => [],
        'lastSeenAt' => time(),
    ], $d);
}

function v_sess_save(string $id, array $s): void
{
    $s['lastSeenAt'] = time();
    @file_put_contents(v_sess_path($id), json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** هر چند وقت یک‌بار نشست‌ها و کش‌های قدیمی پاک می‌شوند */
function v_sweep(): void
{
    if (random_int(1, 20) !== 1) {
        return;
    }
    $ttl = (int) vcfg('session_ttl');
    foreach (['sessions', 'cache'] as $sub) {
        foreach ((array) glob(v_dir($sub) . '/*') as $f) {
            if (is_file($f) && filemtime($f) < time() - $ttl) {
                @unlink($f);
            }
        }
    }
}

/* ───────────── محدودیت نرخ ───────────── */

function v_rate_ok(string $ip, int $limit): bool
{
    $f   = v_dir('cache') . '/r-' . md5($ip) . '.json';
    $now = time();
    $d   = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($d) || (int) ($d['t'] ?? 0) < $now - 3600) {
        $d = ['t' => $now, 'n' => 0];
    }
    $d['n'] = (int) $d['n'] + 1;
    @file_put_contents($f, json_encode($d), LOCK_EX);
    return $d['n'] <= $limit;
}

/* ───────────── اطلاع‌رسانی رزرو ───────────── */

/**
 * اطلاع‌رسانی رزرو تازه.
 * دو راه: پیام بله/تلگرام، و یک وبهوک عمومی.
 */
function v_notify(array $lead): void
{
    $text = str_replace(
        ['{name}', '{phone}', '{slot}', '{clinic}'],
        [
            (string) ($lead['name'] ?? ''),
            (string) ($lead['phone'] ?? ''),
            (string) ($lead['slot'] ?? ''),
            (string) vset('clinic'),
        ],
        (string) vset('notify_text')
    );

    // ── بله / تلگرام ──
    $tok  = trim((string) vset('notify_bale_token'));
    $chat = trim((string) vset('notify_bale_chat'));
    if ($tok !== '' && $chat !== '') {
        $r = v_http('https://tapi.bale.ai/bot' . $tok . '/sendMessage', [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json'],
            'body'    => json_encode([
                'chat_id' => $chat,
                'text'    => $text,
            ], JSON_UNESCAPED_UNICODE),
            'timeout' => 10,
        ]);
        v_log('notify(bale) ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
    }

    // ── وبهوک عمومی ──
    $url = trim((string) vset('notify_url'));
    if ($url !== '') {
        $r = v_http($url, [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json'],
            'body'    => json_encode($lead + ['text' => $text], JSON_UNESCAPED_UNICODE),
            'timeout' => 8,
        ]);
        v_log('notify(webhook) ' . $r['code']);
    }
}

/* ───────────── خروجی ───────────── */

function v_out_audio(string $bytes, string $mime = 'audio/mpeg'): void
{
    if ($bytes === '') {
        v_out_err('tts_failed', 502);
    }
    if (!headers_sent()) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
    }
    echo $bytes;
    exit;
}

function v_out_err(string $code, int $status = 400, string $detail = ''): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => false, 'err' => $code, 'detail' => $detail], JSON_UNESCAPED_UNICODE);
    exit;
}

/** پیام خطا را می‌گوید و صدا را برمی‌گرداند؛ اگر TTS خراب بود، JSON خطا */
function v_say_or_err(string $text, string $code = 'server'): void
{
    $a = v_tts_cached($text);
    if ($a === '') {
        v_out_err($code, 502);
    }
    v_out_audio($a);
}
