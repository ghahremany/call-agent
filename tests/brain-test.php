<?php
/**
 * آزمون ماشین حالت گفتگو — با انبار و مدل جعلی، بدون شبکه.
 * اجرا:  php tests/brain-test.php
 */
declare(strict_types=1);

define('VOICE_APP', 1);

/* ── جایگزین‌های جعلی (به‌جای voice-core و voice-store) ── */

function vcfg(?string $k = null)
{
    static $c = null;
    if ($c === null) {
        $c = (array) require __DIR__ . '/../voice-config.php';
    }
    return $k === null ? $c : ($c[$k] ?? null);
}

function v_log(string $m): void
{
}

/** دانشنامهٔ جعلی — آزمون‌ها در حالت «نوبت» اجرا می‌شوند */
function kb_doc(string $kind = 'personal'): array
{
    $mode = $GLOBALS['KB_MODE'] ?? 'booking';
    return [
        'id' => 1, 'kind' => $kind, 'content' => 'دانشنامهٔ آزمون',
        'title' => $mode === 'contact' ? 'محمد جواد قهرمانی' : 'کلینیک آزمون',
        'greeting' => $mode === 'contact'
            ? 'سلام، من دستیار صوتی محمد جواد قهرمانی هستم. چطور می‌تونم کمکتون کنم؟'
            : vcfg('greeting'),
        'mode' => $mode, 'note' => '', 'at' => '',
    ];
}

/** در آزمون، بازنویسی تنظیمات وجود ندارد */
function vset(string $k, $d = null)
{
    $v = vcfg($k);
    return $v !== null && $v !== '' ? $v : $d;
}

function v_notify(array $l): void
{
}

/** انبار جعلی: سه وقت آزاد */
$GLOBALS['SLOTS'] = [
    'شنبه ۲۶ مهر ساعت ۱۰:۰۰',
    'شنبه ۲۶ مهر ساعت ۱۶:۳۰',
    'یکشنبه ۲۷ مهر ساعت ۱۱:۰۰',
];
$GLOBALS['BOOKED'] = [];

function slots_free(int $limit = 6): array
{
    return array_values(array_filter(
        $GLOBALS['SLOTS'],
        static fn($s) => !isset($GLOBALS['BOOKED'][$s])
    ));
}

function slot_take(string $slot, string $name, string $phone, string $sid): bool
{
    if (isset($GLOBALS['BOOKED'][$slot])) {
        return false;
    }
    $GLOBALS['BOOKED'][$slot] = ['name' => $name, 'phone' => $phone, 'sid' => $sid];
    return true;
}

/** مدل جعلی: پاسخ‌های آماده بر اساس متن */
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [];

function v_llm_understand(array $slots, array $history, string $heard): array
{
    $GLOBALS['AI_CALLS']++;
    foreach ($GLOBALS['AI_SCRIPT'] as $needle => $answer) {
        if (strpos($heard, $needle) !== false) {
            return $answer + ['_provider' => 'fake'];
        }
    }
    return ['intent' => 'other', 'transcript' => $heard, '_provider' => 'fake'];
}

require __DIR__ . '/../voice-nlu.php';
require __DIR__ . '/../voice-brain.php';

/* ── ابزار آزمون ── */

$pass = 0;
$fail = 0;

function check(string $label, $got, $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        printf("  ✓ %s\n", $label);
    } else {
        $fail++;
        printf(
            "  ✗ %s\n      انتظار: %s\n      دریافت: %s\n",
            $label,
            var_export($want, true),
            var_export($got, true)
        );
    }
}

/** یک تماس کامل را اجرا می‌کند و رونوشت را برمی‌گرداند */
function call(string $sid, array $turns, bool $verbose = false): array
{
    $st  = [];
    $r   = brain_start($st);
    $st  = $r['state'];
    $out = [['heard' => '(شروع)', 'say' => $r['say'], 'end' => $r['end'], 'path' => $r['path']]];

    foreach ($turns as $heard) {
        $meta = [];
        $r    = brain_turn($st, $heard, $sid, $meta);
        $st   = $r['state'];
        $out[] = [
            'heard' => $heard,
            'say'   => $r['say'],
            'end'   => $r['end'],
            'path'  => $r['path'],
            'stage' => $st['stage'],
        ];
        if ($r['end']) {
            break;
        }
    }

    if ($verbose) {
        foreach ($out as $t) {
            printf("    %-28s → %s   [%s]\n", $t['heard'], $t['say'], $t['path']);
        }
    }
    return $out;
}

/* ═══════════ سناریو ۱: مسیر کامل بدون AI ═══════════ */

echo "\n── سناریو ۱: رزرو کامل با مسیر سریع (بدون AI) ──\n";
$GLOBALS['BOOKED'] = [];
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [];

$t = call('call-1001', [
    'میخوام وقت رزرو کنم',
    'بله',
    'اسمم مریم رضایی و شمارم صفر نهصد و دوازده سیصد و چهل و پنج ششصد و هفتاد و هشت نه',
], true);

check('خوش‌آمد', $t[0]['say'], vcfg('greeting'));
check('پیشنهاد وقت', $t[1]['say'], 'حتماً، شنبه ۲۶ مهر ساعت ۱۰:۰۰ وقت خالی داریم. تاییده، ثبت کنم؟');
check('درخواست نام و شماره', $t[2]['say'], 'لطفاً نام و نام خانوادگی و شماره موبایلتون رو بفرمایید.');
check('تایید نهایی', $t[3]['say'], 'ممنونم، وقتتون ثبت شد. منتظرتون هستیم. خداحافظ.');
check('تماس تمام شد', $t[3]['end'], true);
check('صفر بار AI صدا زده شد', $GLOBALS['AI_CALLS'], 0);
check('رزرو در انبار ثبت شد', $GLOBALS['BOOKED']['شنبه ۲۶ مهر ساعت ۱۰:۰۰']['name'] ?? null, 'مریم رضایی');
check('شماره درست ثبت شد', $GLOBALS['BOOKED']['شنبه ۲۶ مهر ساعت ۱۰:۰۰']['phone'] ?? null, '09123456789');

/* ═══════════ سناریو ۲: رد وقت و گرفتن وقت بعدی ═══════════ */

echo "\n── سناریو ۲: رد وقت پیشنهادی ──\n";
$GLOBALS['BOOKED'] = [];
$GLOBALS['AI_CALLS'] = 0;

$t = call('call-1002', [
    'میخوام نوبت بگیرم',
    'نه',
    'بله',
    'علی محمدی ۰۹۱۲۳۴۵۶۷۸۹',
], true);

check('وقت اول پیشنهاد شد', $t[1]['say'], 'حتماً، شنبه ۲۶ مهر ساعت ۱۰:۰۰ وقت خالی داریم. تاییده، ثبت کنم؟');
check('وقت دوم پیشنهاد شد', $t[2]['say'], 'حتماً، شنبه ۲۶ مهر ساعت ۱۶:۳۰ وقت خالی داریم. تاییده، ثبت کنم؟');
check('رزرو روی وقت دوم', $GLOBALS['BOOKED']['شنبه ۲۶ مهر ساعت ۱۶:۳۰']['name'] ?? null, 'علی محمدی');
check('صفر بار AI', $GLOBALS['AI_CALLS'], 0);

/* ═══════════ سناریو ۳: سؤال → مدل زبانی ═══════════ */

echo "\n── سناریو ۳: سؤال قیمت → مدل زبانی ──\n";
$GLOBALS['BOOKED'] = [];
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [
    'قیمت' => [
        'intent'  => 'other',
        'reply'   => 'کارشناس ما در تماس توضیح میدن. برای رزرو وقت، اسم و شماره تماستون رو بفرمایید.',
        'transcript' => 'قیمت لیزر چنده؟',
    ],
];

$t = call('call-1003', ['قیمت لیزر چنده؟'], true);
check('مدل صدا زده شد', $GLOBALS['AI_CALLS'], 1);
check('پاسخ مدل گفته شد', $t[1]['say'], 'کارشناس ما در تماس توضیح میدن. برای رزرو وقت، اسم و شماره تماستون رو بفرمایید.');
check('در مرحلهٔ idle ماند', $t[1]['stage'], 'idle');

/* ═══════════ سناریو ۴: خداحافظی ═══════════ */

echo "\n── سناریو ۴: خداحافظی ──\n";
$GLOBALS['AI_CALLS'] = 0;
$t = call('call-1004', ['خداحافظ'], true);
check('خداحافظی گفته شد', $t[1]['say'], 'خواهش می‌کنم. روز خوبی داشته باشید، خداحافظ.');
check('تماس تمام شد', $t[1]['end'], true);
check('صفر بار AI', $GLOBALS['AI_CALLS'], 0);

/* ═══════════ سناریو ۵: زمان مشخص → مدل وقت را تطبیق می‌دهد ═══════════ */

echo "\n── سناریو ۵: زمان مشخص → تطبیق وقت با مدل ──\n";
$GLOBALS['BOOKED'] = [];
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [
    'پنجشنبه' => [
        'intent'         => 'booking_request',
        'requested_time' => 'پنجشنبه',
        'requested_slot' => 'یکشنبه ۲۷ مهر ساعت ۱۱:۰۰',
        'transcript'     => 'برای پنجشنبه وقت دارید؟',
    ],
];

$t = call('call-1005', ['برای پنجشنبه وقت دارید؟'], true);
check('مدل صدا زده شد', $GLOBALS['AI_CALLS'], 1);
check('وقتِ تطبیق‌داده‌شده پیشنهاد شد', $t[1]['say'], 'حتماً، یکشنبه ۲۷ مهر ساعت ۱۱:۰۰ وقت خالی داریم. تاییده، ثبت کنم؟');

/* ═══════════ سناریو ۶: وقت توسط دیگری گرفته شده ═══════════ */

echo "\n── سناریو ۶: وقت بین راه گرفته شد ──\n";
$GLOBALS['BOOKED'] = [];
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [];

$st = [];
$r  = brain_start($st);
$st = $r['state'];
$r  = brain_turn($st, 'میخوام وقت رزرو کنم', 'call-1006');
$st = $r['state'];
$offered = $st['slot'];
$r  = brain_turn($st, 'بله', 'call-1006');
$st = $r['state'];

// کس دیگری همان وقت را می‌گیرد
$GLOBALS['BOOKED'][$offered] = ['name' => 'شخص دیگر', 'phone' => '09120000000', 'sid' => 'other'];

$r = brain_turn($st, 'اسمم زهرا کریمی ۰۹۱۲۱۱۱۲۲۳۳', 'call-1006');
check('وقت تازه پیشنهاد شد', $r['path'], 'rebook');
check('وقت تازه ≠ وقت قبلی', $r['state']['slot'] !== $offered, true);
check('رزرو اشتباه ثبت نشد', $GLOBALS['BOOKED'][$offered]['name'], 'شخص دیگر');

/* ═══════════ سناریو ۷: خداحافظی در میانهٔ گرفتن اطلاعات ═══════════ */

echo "\n── سناریو ۷: خداحافظی وسط گرفتن اطلاعات ──\n";
$GLOBALS['BOOKED'] = [];
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [
    'خداحافظ' => ['intent' => 'goodbye', 'transcript' => 'خداحافظ'],
];

$st = [];
$r  = brain_start($st);
$st = $r['state'];
$r  = brain_turn($st, 'میخوام وقت رزرو کنم', 'call-1007');
$st = $r['state'];
$r  = brain_turn($st, 'بله', 'call-1007');
$st = $r['state'];
check('در مرحلهٔ گرفتن اطلاعات', $st['stage'], 'awaiting_info');

$r = brain_turn($st, 'خداحافظ', 'call-1007');
check('مدل خداحافظی را فهمید', $GLOBALS['AI_CALLS'], 1);
check('تماس تمام شد', $r['end'], true);
check('پیام خداحافظی', $r['say'], 'خواهش می‌کنم. روز خوبی داشته باشید، خداحافظ.');

/* ═══════════ سناریو ۸: بدون وقت آزاد ═══════════ */

echo "\n── سناریو ۸: وقت آزاد نداریم ──\n";
$GLOBALS['SLOTS'] = [];
$GLOBALS['BOOKED'] = [];
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [];

$t = call('call-1008', ['میخوام وقت رزرو کنم'], true);
check('پیام نبود وقت', $t[1]['say'], 'متأسفانه فعلاً وقت خالی دیگه‌ای نداریم.');
check('تماس تمام شد', $t[1]['end'], true);

/* ═══════════ سناریو ۹: حالت «تماس» (دستیار شخصی) ═══════════ */

echo "\n── سناریو ۹: حالت تماس — دستیار شخصی ──\n";
$GLOBALS['BOOKED'] = [];
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [];

$GLOBALS['KB_MODE'] = 'contact';

$st = [];
$r  = brain_start($st, 'personal');
$st = $r['state'];
check('حالت تماس از دانشنامه خوانده شد', $st['mode'], 'contact');
check('خوش‌آمد دستیار شخصی', $r['say'], 'سلام، من دستیار صوتی محمد جواد قهرمانی هستم. چطور می‌تونم کمکتون کنم؟');

$r = brain_turn($st, 'میخوام با شما تماس بگیرم', 'call-2001');
$st = $r['state'];
check('درخواست تماس شناخته شد', $st['stage'], 'awaiting_info');
check('راه سریع، بدون AI', $GLOBALS['AI_CALLS'], 0);
check('درخواست نام و شماره', $r['say'], 'لطفاً اسم و شماره تماستون رو بفرمایید تا خودم باهاتون تماس بگیرم.');

$r = brain_turn($st, 'اسمم مریم رضایی و شمارم صفر نهصد و دوازده سیصد و چهل و پنج ششصد و هفتاد و هشت نه', 'call-2001');
check('سرنخ ثبت شد', $r['path'], 'lead');
check('تماس تمام شد', $r['end'], true);
check('پیام پایانی', $r['say'], 'ممنون، ثبت شد. در اولین فرصت تماس می‌گیرم. روز خوبی داشته باشید.');
check('وقتی رزرو نشد (حالت تماس)', count($GLOBALS['BOOKED']), 0);

echo "\n── سناریو ۱۰: سؤال در حالت تماس → مدل ──\n";
$GLOBALS['AI_CALLS'] = 0;
$GLOBALS['AI_SCRIPT'] = [
    'پروژه' => [
        'intent' => 'other',
        'reply'  => 'ایشون سامانهٔ نگهبانیار رو ساختن و روی n8n هم کار می‌کنن.',
        'transcript' => 'چه پروژه‌هایی دارن؟',
    ],
];

$st = [];
$r  = brain_start($st, 'personal');
$st = $r['state'];

$r = brain_turn($st, 'چه پروژه‌هایی دارن؟', 'call-2002');
check('مدل صدا زده شد', $GLOBALS['AI_CALLS'], 1);
check('پاسخ از دانشنامه', $r['say'], 'ایشون سامانهٔ نگهبانیار رو ساختن و روی n8n هم کار می‌کنن.');
check('در حالت idle ماند', $r['state']['stage'], 'idle');

echo "\n── سناریو ۱۱: خداحافظی در حالت تماس ──\n";
$GLOBALS['AI_CALLS'] = 0;
$st = [];
$r  = brain_start($st, 'personal');
$st = $r['state'];
$r = brain_turn($st, 'خداحافظ', 'call-2003');
check('خداحافظی بدون AI', $GLOBALS['AI_CALLS'], 0);
check('تماس تمام شد', $r['end'], true);

echo "\n";
printf("نتیجه: %d موفق، %d ناموفق\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
