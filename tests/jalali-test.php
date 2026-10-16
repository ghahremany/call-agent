<?php
declare(strict_types=1);
define('VOICE_APP', 1);
require __DIR__ . '/../voice-jalali.php';

$pass = 0; $fail = 0;
function check(string $l, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ✓ %s\n", $l); }
    else { $fail++; printf("  ✗ %s\n      انتظار: %s\n      دریافت: %s\n", $l, var_export($want, true), var_export($got, true)); }
}

echo "\n── تبدیل میلادی → شمسی ──\n";
// نقاط مرجع شناخته‌شده
check('2026-03-21 = 1 فروردین 1405', j_from_greg(2026, 3, 21), [1405, 1, 1]);
check('2026-10-17 = 25 مهر 1405',   j_from_greg(2026, 10, 17), [1405, 7, 25]);
check('2024-03-20 = 1 فروردین 1403', j_from_greg(2024, 3, 20), [1403, 1, 1]);
check('2025-03-21 = 1 فروردین 1404', j_from_greg(2025, 3, 21), [1404, 1, 1]);
check('2026-01-01 = 11 دی 1404',    j_from_greg(2026, 1, 1), [1404, 10, 11]);
check('2000-01-01 = 11 دی 1378',    j_from_greg(2000, 1, 1), [1378, 10, 11]);

echo "\n── تبدیل شمسی → میلادی (رفت‌وبرگشت) ──\n";
foreach ([[1405,1,1],[1405,7,25],[1403,12,30],[1404,12,29],[1399,1,1],[1410,6,31]] as $j) {
    $g = j_to_greg($j[0], $j[1], $j[2]);
    $back = j_from_greg($g[0], $g[1], $g[2]);
    check(sprintf('%d/%02d/%02d رفت‌وبرگشت', $j[0], $j[1], $j[2]), $back, $j);
}

echo "\n── روز هفته ──\n";
check('2026-10-17 شنبه است', J_WEEKDAYS[j_weekday(1405, 7, 25)], 'شنبه');
check('2026-03-21 شنبه است', J_WEEKDAYS[j_weekday(1405, 1, 1)], 'شنبه');
check('2026-03-20 جمعه است', J_WEEKDAYS[j_weekday(1404, 12, 29)], 'جمعه');

echo "\n── طول ماه و کبیسه ──\n";
check('فروردین ۳۱ روز', j_month_len(1405, 1), 31);
check('مهر ۳۰ روز', j_month_len(1405, 7), 30);
check('اسفند 1403 کبیسه (۳۰)', j_month_len(1403, 12), 30);
check('اسفند 1404 عادی (۲۹)', j_month_len(1404, 12), 29);
check('1403 کبیسه', j_is_leap(1403), true);
check('1404 کبیسه نیست', j_is_leap(1404), false);

echo "\n── قالب‌بندی ──\n";
check('قالب کامل', j_format(1405, 7, 25), 'شنبه ۲۵ مهر ۱۴۰۵');
check('قالب کوتاه', j_format_short(1405, 7, 25), '۲۵ مهر');
check('ارقام فارسی', j_fa_num('1405'), '۱۴۰۵');
check('ارقام لاتین', j_en_num('۱۴۰۵'), '1405');

echo "\n── جابه‌جایی روز ──\n";
check('۲۵ مهر + ۷ روز = ۲ آبان', j_add_days(1405, 7, 25, 7), [1405, 8, 2]);
check('۲۵ مهر - ۲۵ روز = 31 شهریور', j_add_days(1405, 7, 25, -25), [1405, 6, 31]);
check('1 فروردین - 1 روز = 29 اسفند 1404', j_add_days(1405, 1, 1, -1), [1404, 12, 29]);
check('ISO ↔ شمسی', j_from_iso('2026-10-17'), [1405, 7, 25]);
check('شمسی → ISO', j_to_iso(1405, 7, 25), '2026-10-17');

echo "\n";
printf("نتیجه: %d موفق، %d ناموفق\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
