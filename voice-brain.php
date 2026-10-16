<?php
/**
 * مغز منشی — ماشین حالت گفتگو.
 *
 * دو حالت دارد:
 *   contact → دستیار شخصی: به سؤال‌ها جواب می‌دهد و اگر کسی خواست تماس بگیرد،
 *             نام و شماره و موضوع را می‌گیرد. (برای سایت شخصی)
 *   booking → منشی نوبت‌ده: وقت آزاد پیشنهاد می‌دهد و رزرو ثبت می‌کند.
 *             (برای کلینیک یا دموی محصول)
 *
 * مراحل: idle → awaiting_confirm → awaiting_info → ended
 * ترتیب: اول Fast Path (بدون AI)، بعد اگر لازم شد مدل زبانی.
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

/* ───────────── دانشنامهٔ جاری این تماس ───────────── */

function brain_kb(?array $set = null): array
{
    static $kb = null;
    if ($set !== null) {
        $kb = $set;
    }
    return $kb ?? ['kind' => 'personal', 'title' => '', 'greeting' => '', 'mode' => 'contact'];
}

/* ───────────── متن‌های منشی ───────────── */

function brain_text(string $key, array $vars = []): string
{
    $kb   = brain_kb();
    $mode = (string) ($kb['mode'] ?? 'contact');

    // در حالت «تماس»، دو متن فرق دارند
    if ($mode === 'contact') {
        if ($key === 'ask_info') { $key = 'ask_info_contact'; }
        if ($key === 'final')    { $key = 'final_contact'; }
    }

    if ($key === 'greeting' && ($kb['greeting'] ?? '') !== '') {
        $t = (string) $kb['greeting'];
    } elseif ($key === 'clinic' && ($kb['title'] ?? '') !== '') {
        $t = (string) $kb['title'];
    } else {
        $t = (string) vset($key);
    }
    foreach ($vars as $a => $b) {
        $t = str_replace('{' . $a . '}', (string) $b, $t);
    }
    return $t;
}

function brain_offer(string $slot): string
{
    return brain_text('offer', ['slot' => $slot]);
}

/** اولین وقتی که هنوز پیشنهاد نشده؛ اگر همه پیشنهاد شده‌اند، اولی */
function brain_pick(array $slots, array $proposed): string
{
    foreach ($slots as $s) {
        if (!in_array($s, $proposed, true)) {
            return (string) $s;
        }
    }
    return (string) ($slots[0] ?? '');
}

function brain_propose(array &$st, string $slot): void
{
    $p = is_array($st['proposed'] ?? null) ? $st['proposed'] : [];
    if ($slot !== '' && !in_array($slot, $p, true)) {
        $p[] = $slot;
    }
    if (count($p) > 20) {
        $p = array_slice($p, -20);
    }
    $st['proposed'] = $p;
}

/* ───────────── شروع تماس ───────────── */

function brain_start(array $st, string $kind = 'personal'): array
{
    $kb = function_exists('kb_doc') ? kb_doc($kind) : ['kind' => $kind, 'greeting' => '', 'mode' => 'contact'];
    brain_kb($kb);

    $st['kind']     = $kb['kind'];
    $st['mode']     = $kb['mode'];
    $st['stage']    = 'idle';
    $st['slot']     = '';
    $st['name']     = '';
    $st['phone']    = '';
    $st['topic']    = '';
    $st['proposed'] = [];
    $st['turn']     = 0;
    $st['history']  = [];

    return [
        'say'   => brain_text('greeting'),
        'state' => $st,
        'end'   => false,
        'path'  => 'start',
    ];
}

/* ───────────── یک نوبت گفتگو ───────────── */

function brain_turn(array $st, string $heard, string $sid, array &$meta = []): array
{
    $st['turn'] = (int) ($st['turn'] ?? 0) + 1;
    $stage      = (string) ($st['stage'] ?? 'idle');
    $kind       = (string) ($st['kind'] ?? 'personal');
    $mode       = (string) ($st['mode'] ?? 'contact');

    if (function_exists('kb_doc')) {
        brain_kb(kb_doc($kind));
    }
    $slots = $mode === 'booking' ? slots_free(6) : [];

    // سقف نوبت‌ها — تماس بی‌پایان نمی‌گذاریم
    if ($st['turn'] > (int) vset('max_turns')) {
        $st['stage'] = 'ended';
        return brain_result($st, brain_text('goodbye'), true, 'limit');
    }

    /* ── ۱) مسیر سریع: بدون AI ── */

    $route  = fa_route($heard, $stage);
    $action = (string) $route['action'];

    if ($action === 'end') {
        $st['stage'] = 'ended';
        return brain_result($st, brain_text('goodbye'), true, 'fast');
    }

    if ($action === 'book') {                    // در مرحلهٔ awaiting_confirm
        $st['stage'] = 'awaiting_info';
        return brain_result($st, brain_text('ask_info'), false, 'fast');
    }

    if ($action === 'next_slot') {               // وقت پیشنهادی رد شد
        return brain_next_slot($st, $slots, 'fast');
    }

    if ($action === 'got_info') {                // نام یا شماره گرفته شد
        brain_merge($st, ['phone' => fa_phone($heard), 'name' => fa_name($heard)]);
        return brain_after_info($st, $slots, $sid, 'fast');
    }

    /* ── ۲) مسیر سریع برای حالت «تماس» ── */

    if ($mode === 'contact' && $stage === 'idle' && brain_is_contact($heard)) {
        $st['stage'] = 'awaiting_info';
        return brain_result($st, brain_text('ask_info'), false, 'fast');
    }

    /* ── ۳) مسیر سریع برای حالت «نوبت» ── */

    if ($mode === 'booking' && $stage === 'idle' && brain_is_booking($heard)) {
        if (!$slots) {
            $st['stage'] = 'ended';
            return brain_result($st, brain_text('no_slots'), true, 'fast');
        }
        $next = brain_pick($slots, (array) $st['proposed']);
        $st['slot']  = $next;
        $st['stage'] = 'awaiting_confirm';
        brain_propose($st, $next);
        return brain_result($st, brain_offer($next), false, 'fast');
    }

    /* ── ۴) فهم با مدل زبانی ── */

    $ai = v_llm_understand($slots, (array) ($st['history'] ?? []), $heard, $kind);
    $meta['ai'] = $ai['_provider'] ?? 'none';

    if (!$ai) {
        v_log('مدل در دسترس نبود — مسیر ai_down');
        return brain_result($st, brain_text('ai_down'), false, 'ai_down');
    }

    $transcript = trim((string) ($ai['transcript'] ?? ''));
    $intent     = strtolower(trim((string) ($ai['intent'] ?? '')));
    $reply      = trim((string) ($ai['reply'] ?? ''));

    $aiPhone   = (string) (fa_phone_shape((string) ($ai['phone'] ?? '')) ?? '');
    $aiNameRaw = trim((string) ($ai['name'] ?? ''));
    $aiName    = $aiNameRaw !== '' ? (fa_name($aiNameRaw) ?? '') : '';
    $topic     = trim((string) ($ai['topic'] ?? ''));

    $reqSlot = trim((string) ($ai['requested_slot'] ?? ''));
    if ($reqSlot !== '' && !in_array($reqSlot, $slots, true)) {
        $reqSlot = '';
    }

    // خداحافظی — مدل خودش تشخیص داده، پس در هر مرحله‌ای معتبر است.
    // ولی اگر همان جمله نام یا شماره هم داشت، اول آن‌ها را ثبت می‌کنیم.
    if ($intent === 'goodbye' && $aiPhone === '' && $aiName === '') {
        $st['stage'] = 'ended';
        return brain_result($st, brain_text('goodbye'), true, 'ai');
    }

    if ($stage === 'awaiting_info') {
        brain_merge($st, ['phone' => $aiPhone, 'name' => $aiName]);
        if ($topic !== '' && (string) $st['topic'] === '') {
            $st['topic'] = $topic;
        }
        return brain_after_info($st, $slots, $sid, 'ai');
    }

    if ($stage === 'awaiting_confirm') {
        if ($reqSlot !== '' && $reqSlot !== $st['slot']) {
            $st['slot'] = $reqSlot;
            brain_propose($st, $reqSlot);
            return brain_result($st, brain_offer($reqSlot), false, 'ai');
        }
        if ($intent === 'confirm') {
            $st['stage'] = 'awaiting_info';
            return brain_result($st, brain_text('ask_info'), false, 'ai');
        }
        if ($intent === 'reject') {
            return brain_next_slot($st, $slots, 'ai');
        }
    }

    if ($stage === 'idle') {
        // درخواست تماس یا نوبت
        if ($intent === 'booking_request' || $intent === 'contact_request') {
            if ($mode === 'contact') {
                $st['stage'] = 'awaiting_info';
                if ($topic !== '') {
                    $st['topic'] = $topic;
                }
                return brain_result($st, brain_text('ask_info'), false, 'ai');
            }
            if ($reqSlot !== '') {
                $st['slot']  = $reqSlot;
                $st['stage'] = 'awaiting_confirm';
                brain_propose($st, $reqSlot);
                return brain_result($st, brain_offer($reqSlot), false, 'ai');
            }
            if (!$slots) {
                $st['stage'] = 'ended';
                return brain_result($st, brain_text('no_slots'), true, 'ai');
            }
            $next = brain_pick($slots, (array) $st['proposed']);
            $st['slot']  = $next;
            $st['stage'] = 'awaiting_confirm';
            brain_propose($st, $next);
            return brain_result($st, brain_offer($next), false, 'ai');
        }
    }

    // سؤال یا حرف متفرقه: اگر مدل پاسخ داده، همان را بگو
    if ($reply !== '') {
        return brain_result($st, $reply, false, 'ai');
    }
    if ($transcript === '') {
        return brain_result($st, brain_text('didnt_hear'), false, 'ai');
    }

    return brain_result($st, brain_text('ask_info'), false, 'ai');
}

/* ───────────── گام‌های کمکی ───────────── */

function brain_result(array &$st, string $say, bool $end, string $path): array
{
    $st['stage'] = $end ? 'ended' : (string) $st['stage'];
    return ['say' => $say, 'state' => $st, 'end' => $end, 'path' => $path];
}

/** وقت پیشنهادی رد شد → وقت بعدی */
function brain_next_slot(array &$st, array $slots, string $path): array
{
    $next = brain_pick($slots, (array) $st['proposed']);
    if ($next === '') {
        $st['stage'] = 'ended';
        return brain_result($st, brain_text('no_slots'), true, $path);
    }
    $st['slot']  = $next;
    $st['stage'] = 'awaiting_confirm';
    brain_propose($st, $next);
    return brain_result($st, brain_offer($next), false, $path);
}

/** نام/شماره را در حالت می‌نشاند (فقط اگر خالی باشد) */
function brain_merge(array &$st, array $vals): void
{
    foreach (['name', 'phone'] as $k) {
        $v = $vals[$k] ?? null;
        if (is_string($v) && $v !== '' && (string) $st[$k] === '') {
            $st[$k] = $v;
        }
    }
}

/**
 * بعد از گرفتن اطلاعات.
 *   حالت contact → سرنخ ثبت می‌شود و تماس تمام.
 *   حالت booking → وقت رزرو می‌شود.
 */
function brain_after_info(array &$st, array $slots, string $sid, string $path): array
{
    $name  = (string) $st['name'];
    $phone = (string) $st['phone'];
    $mode  = (string) ($st['mode'] ?? 'contact');

    if ($name === '' || $phone === '') {
        if ($name !== '' && $phone === '') {
            return brain_result($st, brain_text('ask_phone'), false, $path);
        }
        if ($name === '' && $phone !== '') {
            return brain_result($st, brain_text('ask_name'), false, $path);
        }
        return brain_result($st, brain_text('ask_info'), false, $path);
    }

    /* ── حالت تماس: فقط سرنخ ثبت می‌شود ── */
    if ($mode === 'contact') {
        brain_save_lead($st, $sid);
        $st['stage'] = 'ended';
        return brain_result($st, brain_text('final'), true, 'lead');
    }

    /* ── حالت نوبت: وقت رزرو می‌شود ── */
    $slot = (string) $st['slot'];
    if ($slot !== '' && slot_take($slot, $name, $phone, $sid)) {
        v_notify(['slot' => $slot, 'name' => $name, 'phone' => $phone, 'session' => $sid, 'at' => date('c')]);
        brain_save_customer($slot, $name, $phone, $sid);
        $st['stage'] = 'ended';
        return brain_result($st, brain_text('final'), true, 'booked');
    }

    // وقت گرفته شده بود → وقت تازه
    $fresh = slots_free(6);
    $next  = brain_pick($fresh, (array) $st['proposed']);
    if ($next !== '') {
        $st['slot']  = $next;
        $st['stage'] = 'awaiting_confirm';
        brain_propose($st, $next);
        return brain_result($st, brain_offer($next), false, 'rebook');
    }
    $st['stage'] = 'ended';
    return brain_result($st, brain_text('no_slots'), true, 'rebook');
}

/* ───────────── ثبت سرنخ و مشتری ───────────── */

function brain_save_lead(array $st, string $sid): void
{
    if (!function_exists('doc_put')) {
        return;
    }
    $name  = (string) $st['name'];
    $phone = (string) $st['phone'];
    $topic = (string) ($st['topic'] ?? '');
    [$jy, $jm, $jd] = j_today();

    doc_put('cust', [
        'name'   => $name,
        'phone'  => $phone,
        'topic'  => $topic,
        'slot'   => '',
        'day'    => j_to_iso($jy, $jm, $jd),
        'jday'   => sprintf('%04d-%02d-%02d', $jy, $jm, $jd),
        'jtext'  => j_format($jy, $jm, $jd),
        'kind'   => (string) ($st['kind'] ?? 'personal'),
        'status' => 'new',
        'note'   => '',
        'sid'    => $sid,
        'at'     => date('c'),
    ]);

    v_notify(['name' => $name, 'phone' => $phone, 'slot' => $topic !== '' ? $topic : 'تماس تلفنی', 'at' => date('c')]);
}

/** ثبت مشتری در پروندهٔ پنل (با تاریخ شمسی برای تقویم) */
function brain_save_customer(string $slot, string $name, string $phone, string $sid): void
{
    if (!function_exists('doc_put')) {
        return;
    }
    $info = function_exists('slot_info') ? slot_info($slot) : null;
    $iso  = (string) ($info['day'] ?? '');
    $j    = $iso !== '' && function_exists('j_from_iso') ? j_from_iso($iso) : null;

    doc_put('cust', [
        'name'   => $name,
        'phone'  => $phone,
        'slot'   => $slot,
        'day'    => $iso,
        'jday'   => $j ? sprintf('%04d-%02d-%02d', $j[0], $j[1], $j[2]) : '',
        'jtext'  => $j ? j_format($j[0], $j[1], $j[2]) : '',
        'kind'   => (string) (brain_kb()['kind'] ?? 'personal'),
        'status' => 'booked',
        'note'   => '',
        'sid'    => $sid,
        'at'     => date('c'),
    ]);
}

/* ───────────── تشخیص درخواست (مسیر سریع) ───────────── */

/** درخواست تماس با خودش: «میخوام تماس بگیرم»، «شمارهم رو بدم» */
function brain_is_contact(string $text): bool
{
    $t = fa_norm($text);
    if ($t === '') {
        return false;
    }
    foreach (['تماسبگیر', 'تماسگرفتن', 'شمارهبدم', 'شمارمو', 'شمارهمو', 'هماهنگکن',
              'ارتباط', 'درتماس', 'پیامبدم', 'ایمیل', 'راهارتباط', 'باهاتماس',
              'باهاشتماس', 'باهاتونتماس', 'بیشتربگم', 'علاقهمندم', 'استخدام',
              'همکاری', 'پروژهدارم', 'سفارش', 'قراربذار'] as $w) {
        if (strpos($t, $w) !== false) {
            return true;
        }
    }
    foreach (['تماس', 'شماره'] as $w) {
        if (strpos($t, $w) !== false && (strpos($t, 'میخوام') !== false || strpos($t, 'میخواستم') !== false
            || strpos($t, 'بدم') !== false || strpos($t, 'بدهم') !== false || strpos($t, 'بگیرم') !== false)) {
            return true;
        }
    }
    return false;
}

/**
 * همان BOOKING_RE ورک‌فلو، ولی روی متن نرمال‌شدهٔ فارسی.
 * اگر کاربر زمان مشخصی گفته باشد، به مدل می‌رویم تا وقت را تطبیق دهد.
 */
function brain_is_booking(string $text): bool
{
    $t = fa_norm($text);
    if ($t === '') {
        return false;
    }
    if (brain_has_explicit_time($t)) {
        return false;
    }
    foreach (['رزرو', 'نوبت', 'وقت'] as $w) {
        if (strpos($t, $w) !== false) {
            return true;
        }
    }
    return false;
}

function brain_has_explicit_time(string $t): bool
{
    foreach ([
        'امروز','فردا','پسفردا','شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه',
        'پنجشنبه','جمعه','صبح','ظهر','عصر','شب','ساعت','دقیقه','نیمه','هفته',
    ] as $w) {
        if (strpos($t, $w) !== false) {
            return true;
        }
    }
    return false;
}
