<?php
/**
 * مغز منشی — ماشین حالت گفتگو.
 *
 * مراحل (هم‌راست با ورک‌فلو):
 *   idle              → خوش‌آمد گفته شده، منتظر درخواست
 *   awaiting_confirm  → یک وقت پیشنهاد شده، منتظر تایید
 *   awaiting_info     → وقت تایید شده، منتظر نام و شماره
 *   ended             → تماس تمام
 *
 * ترتیب تصمیم‌گیری: اول Fast Path (بدون AI)، بعد اگر لازم شد مدل زبانی.
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

/* ───────────── متن‌های منشی ───────────── */

function brain_text(string $key, array $vars = []): string
{
    $t = (string) vset($key);
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

function brain_start(array $st): array
{
    $st['stage']    = 'idle';
    $st['slot']     = '';
    $st['name']     = '';
    $st['phone']    = '';
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
    $slots      = slots_free(6);

    // سقف نوبت‌ها — تماس بی‌پایان نمی‌گذاریم
    if ($st['turn'] > (int) vcfg('max_turns')) {
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
        brain_merge($st, [
            'phone' => fa_phone($heard),
            'name'  => fa_name($heard),
        ]);
        return brain_after_info($st, $slots, $sid, 'fast');
    }

    // درخواست رزرو ساده، بدون زمان مشخص → همان مسیر سریع
    if ($stage === 'idle' && brain_is_booking($heard)) {
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

    /* ── ۲) فهم با مدل زبانی ── */

    $ai = v_llm_understand($slots, (array) ($st['history'] ?? []), $heard);
    $meta['ai'] = $ai['_provider'] ?? 'none';

    if (!$ai) {
        // مدل در دسترس نبود (کلید تنظیم نشده یا سرویس قطع است)
        v_log('مدل در دسترس نبود — مسیر ai_down');
        return brain_result($st, brain_text('ai_down'), false, 'ai_down');
    }

    $transcript = trim((string) ($ai['transcript'] ?? ''));
    $intent     = strtolower(trim((string) ($ai['intent'] ?? '')));
    $reply      = trim((string) ($ai['reply'] ?? ''));

    // شماره و نام را از خروجی مدل بگیر، ولی همیشه از فیلترهای خودمان هم بگذران
    $aiPhone = (string) (fa_phone_shape((string) ($ai['phone'] ?? '')) ?? '');
    $aiNameRaw = trim((string) ($ai['name'] ?? ''));
    $aiName  = $aiNameRaw !== '' ? (fa_name($aiNameRaw) ?? '') : '';

    // وقت درخواستی فقط اگر دقیقاً یکی از وقت‌های آزاد باشد
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
        if ($reqSlot !== '') {
            $st['slot']  = $reqSlot;
            $st['stage'] = 'awaiting_confirm';
            brain_propose($st, $reqSlot);
            return brain_result($st, brain_offer($reqSlot), false, 'ai');
        }
        if ($intent === 'booking_request') {
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

    // آخرین سنگر: بپرس چه می‌خواهد
    return brain_result($st, brain_text('ask_info'), false, 'ai');
}

/* ───────────── گام‌های کمکی ───────────── */

/** نتیجه را می‌سازد و گفتگو را در تاریخچه ثبت می‌کند */
function brain_result(array &$st, string $say, bool $end, string $path): array
{
    $st['stage'] = $end ? 'ended' : (string) $st['stage'];
    return [
        'say'   => $say,
        'state' => $st,
        'end'   => $end,
        'path'  => $path,
    ];
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
 * بعد از گرفتن اطلاعات: اگر نام و شماره کامل شد، رزرو ثبت می‌شود.
 * اگر وقت بین این مدت توسط کس دیگری گرفته شده باشد، وقت تازه پیشنهاد می‌شود.
 */
function brain_after_info(array &$st, array $slots, string $sid, string $path): array
{
    $name  = (string) $st['name'];
    $phone = (string) $st['phone'];

    if ($name !== '' && $phone !== '') {
        $slot = (string) $st['slot'];
        if ($slot !== '' && slot_take($slot, $name, $phone, $sid)) {
            v_notify([
                'slot' => $slot, 'name' => $name, 'phone' => $phone,
                'session' => $sid, 'at' => date('c'),
            ]);
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

    if ($name !== '' && $phone === '') {
        return brain_result($st, brain_text('ask_phone'), false, $path);
    }
    if ($name === '' && $phone !== '') {
        return brain_result($st, brain_text('ask_name'), false, $path);
    }
    return brain_result($st, brain_text('ask_info'), false, $path);
}

/* ───────────── تشخیص درخواست رزرو (مسیر سریع) ───────────── */

/** ثبت مشتری در پروندهٔ پنل (با تاریخ شمسی برای تقویم) */
function brain_save_customer(string $slot, string $name, string $phone, string $sid): void
{
    if (!function_exists('doc_put')) {
        return;   // در آزمون‌های واحد انبار اسناد بارگذاری نمی‌شود
    }
    $info = function_exists('slot_info') ? slot_info($slot) : null;
    $iso  = (string) ($info['day'] ?? '');
    $j    = $iso !== '' && function_exists('j_from_iso') ? j_from_iso($iso) : null;

    doc_put('cust', [
        'name'    => $name,
        'phone'   => $phone,
        'slot'    => $slot,
        'day'     => $iso,                                  // میلادی: برای مرتب‌سازی
        'jday'    => $j ? sprintf('%04d-%02d-%02d', $j[0], $j[1], $j[2]) : '',  // شمسی: برای تقویم
        'jtext'   => $j ? j_format($j[0], $j[1], $j[2]) : '',
        'status'  => 'booked',
        'note'    => '',
        'sid'     => $sid,
        'at'      => date('c'),
    ]);
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
        return false;   // زمان مشخص → مدل باید وقت را پیدا کند
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
        'امروز','فردا','پسفردا','شنبه','یکشنبه','دوشنبه','سهشنبه','چهارشنبه',
        'پنجشنبه','جمعه','صبح','ظهر','عصر','شب','ساعت','دقیقه','نیمه','هفته',
    ] as $w) {
        if (strpos($t, $w) !== false) {
            return true;
        }
    }
    return false;
}
