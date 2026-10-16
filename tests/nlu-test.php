<?php
/**
 * آزمون لایهٔ فهم فارسی — بدون AI، بدون شبکه.
 * اجرا:  php tests/nlu-test.php
 */
declare(strict_types=1);

define('VOICE_APP', 1);
require __DIR__ . '/../voice-nlu.php';

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

echo "\n── نرمال‌سازی ──\n";
check('اعداد فارسی', fa_norm('۰۹۱۲'), '0912');
check('اعداد عربی', fa_norm('٠٩١٢'), '0912');
check('ي عربی → ی', fa_norm('يكی'), 'یکی');
check('نیم‌فاصله در استخراج حذف', fa_norm('می‌خواهم'), 'میخواهم');
check('نیم‌فاصله در تطبیق → فاصله', trim(fa_plain('می‌خواهم')), 'می خواهم');

echo "\n── مرز کلمه (باگ «نهصد») ──\n";
check('«نهصد» رد نیست', fa_is_reject('نهصد و دوازده'), false);
check('«نه» رد است', fa_is_reject('نه'), true);
check('«نهصد» تایید نیست', fa_is_confirm('نهصد'), false);
check('«بله» تایید است', fa_is_confirm('بله'), true);

echo "\n── قصدهای ساده ──\n";
check('آره', fa_is_confirm('آره'), true);
check('بله درسته', fa_is_confirm('بله درسته'), true);
check('تایید می‌کنم', fa_is_confirm('تایید می‌کنم'), true);
check('ثبتش کن', fa_is_confirm('ثبتش کن'), true);
check('اشتباهه', fa_is_reject('اشتباهه'), false);
check('نمی‌شه', fa_is_reject('نمی‌شه'), true);
check('زودتر ندارید', fa_is_reject('زودتر ندارید'), true);
check('خداحافظ', fa_is_farewell_only('خداحافظ'), true);
check('ممنون خداحافظ ≠ فقط خداحافظ', fa_is_farewell_only('ممنون خداحافظ'), false);
check('ممنون', fa_is_farewell_only('ممنون'), true);
check('سلام ≠ خداحافظ', fa_is_farewell_only('سلام'), false);

echo "\n── شمارهٔ تماس ──\n";
check('ارقام لاتین', fa_phone('شمارم 09124567890'), '09124567890');
check('ارقام فارسی', fa_phone('۰۹۱۲۴۵۶۷۸۹۰'), '09124567890');
check(
    'سه‌رقمی حرفی (حالت واقعی، ۱۱ رقم)',
    fa_phone('شمارهٔ تماستم صفر نهصد و دوازده، سیصد و چهل و پنج، ششصد و هفتاد و هشت، نه'),
    '09123456789'
);
check(
    'سه‌رقمی بدون جداکننده',
    fa_phone('صفر نهصد و دوازده سیصد و چهل و پنج ششصد و هفتاد و هشت نه'),
    '09123456789'
);
check(
    'سه‌رقمی با «و» چسباننده',
    fa_phone('صفر نهصد و دوازده و سیصد و چهل و پنج و ششصد و هفتاد و هشت و نه'),
    '09123456789'
);
check(
    'تلفن ثابت با اعداد حرفی',
    fa_phone('صفر بیست و یک هشت هشت هفت هفت شش شش پنج پنج'),
    '02188776655'
);
check(
    'بدون صفر ابتدایی (ده رقم)',
    fa_phone('نهصد و دوازده چهارصد و پنجاه و شش هفتصد و هشتاد و نه صفر'),
    '09124567890'
);
check(
    'شمارهٔ ناقص پذیرفته نمی‌شود',
    fa_phone('نهصد و دوازده چهارصد و پنجاه و شش هفتصد و هشتاد و نه'),
    null
);
check('رقم‌به‌رقم', fa_phone('صفر نه یک دو چهار پنج شش هفت هشت نه صفر'), '09124567890');
check('پیش‌شمارهٔ بین‌المللی', fa_phone('+989124567890'), '09124567890');
check('تلفن ثابت', fa_phone('تلفنم 02188776655'), '02188776655');
check('موبایل ۱۰ رقمی ناقص رد می‌شود', fa_phone('صفر نهصد و دوازده چهارصد و پانزده ششصد و هشتاد و نه'), null);
check('موبایل کامل ۱۱ رقمی', fa_phone('صفر نهصد و دوازده چهارصد و پانزده ششصد و هشتاد و نه یک'), '09124156891');
check('بدون شماره', fa_phone('سلام میخواستم وقت بگیرم'), null);
check('عدد کوتاه نادیده', fa_phone('ساعت ۱۰ صبح'), null);

echo "\n── نام ──\n";
check('اسمم مریم رضایی هست', fa_name('اسمم مریم رضایی هست'), 'مریم رضایی');
check('من علی محمدی هستم', fa_name('من علی محمدی هستم'), 'علی محمدی');
check('فقط سلام', fa_name('سلام'), null);
check('جملهٔ بی‌نام', fa_name('میخواستم وقت بگیرم'), null);
check('سؤال قیمت نام نیست', fa_name('قیمت لیزر چنده'), null);
check('نام همراه شماره (ارقام حذف می‌شوند)', fa_name('مریم رضایی 09124567890'), 'مریم رضایی');
check('خداحافظ نام نیست', fa_name('خداحافظ'), null);
check('ممنون نام نیست', fa_name('ممنون'), null);

echo "\n── Fast Path Router ──\n";
check('خداحافظی', fa_route('خداحافظ', 'idle')['action'], 'end');
check('خداحافظی در میانهٔ گرفتن اطلاعات', fa_route('خداحافظ', 'awaiting_info')['action'], 'ai');
check('تایید در مرحلهٔ پیشنهاد', fa_route('بله درسته', 'awaiting_confirm')['action'], 'book');
check('رد در مرحلهٔ پیشنهاد', fa_route('نه', 'awaiting_confirm')['action'], 'next_slot');
check(
    'شماره در مرحلهٔ اطلاعات',
    fa_route('صفر نهصد و دوازده سیصد و چهل و پنج ششصد و هفتاد و هشت نه', 'awaiting_info')['action'],
    'got_info'
);
check(
    'شمارهٔ ناقص در مرحلهٔ اطلاعات → AI',
    fa_route('صفر نهصد و دوازده چهارصد و پنجاه و شش هفتصد و هشتاد و نه', 'awaiting_info')['action'],
    'ai'
);
check('نام در مرحلهٔ اطلاعات', fa_route('اسمم مریم رضایی', 'awaiting_info')['action'], 'got_info');
check('سؤال قیمت → AI', fa_route('قیمت لیزر چنده؟', 'idle')['action'], 'ai');
check('نیاز به AI', fa_route('قیمت لیزر چنده؟', 'idle')['needs_ai'], true);
check('بی‌نیاز از AI', fa_route('بله', 'awaiting_confirm')['needs_ai'], false);
check('درخواست رزرو → AI (تشخیص با مدل)', fa_route('میخوام وقت رزرو کنم', 'idle')['action'], 'ai');

echo "\n";
printf("نتیجه: %d موفق، %d ناموفق\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
