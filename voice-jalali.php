<?php
/**
 * تقویم هجری شمسی — تبدیل، قالب‌بندی، و ابزارهای تقویم.
 * بدون هیچ وابستگی؛ الگوریتم استاندارد بورکوفسکی.
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

const J_MONTHS = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
];

// هفتهٔ ایرانی از شنبه شروع می‌شود
const J_WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

/** میلادی → شمسی */
function j_from_greg(int $gy, int $gm, int $gd): array
{
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
          + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];

    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/** شمسی → میلادی */
function j_to_greg(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
          + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0 ? 29 : 28,
              31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) {
        $gd -= $sal_a[$gm];
    }
    return [$gy, $gm, $gd];
}

/** امروز به شمسی (وقت تهران) */
function j_today(): array
{
    $t = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    return j_from_greg((int) $t->format('Y'), (int) $t->format('n'), (int) $t->format('j'));
}

/** شمارهٔ روز هفته: ۰=شنبه … ۶=جمعه */
function j_weekday(int $jy, int $jm, int $jd): int
{
    [$gy, $gm, $gd] = j_to_greg($jy, $jm, $jd);
    $w = (int) (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $gy, $gm, $gd)))->format('w');
    return ($w + 1) % 7;          // یکشنبه=0 در PHP → شنبه=0 اینجا
}

/** تعداد روزهای ماه شمسی */
function j_month_len(int $jy, int $jm): int
{
    if ($jm <= 6) {
        return 31;
    }
    if ($jm <= 11) {
        return 30;
    }
    // اسفند: سال کبیسه ۳۰ روز
    return j_is_leap($jy) ? 30 : 29;
}

function j_is_leap(int $jy): bool
{
    $r = $jy % 33;
    return in_array($r, [1, 5, 9, 13, 17, 22, 26, 30], true);
}

/** «شنبه ۲۶ مهر ۱۴۰۵» */
function j_format(int $jy, int $jm, int $jd, bool $withWeekday = true): string
{
    $s = ($withWeekday ? J_WEEKDAYS[j_weekday($jy, $jm, $jd)] . ' ' : '')
       . j_fa_num($jd) . ' ' . (J_MONTHS[$jm] ?? '') . ' ' . j_fa_num($jy);
    return $s;
}

/** «۲۶ مهر» */
function j_format_short(int $jy, int $jm, int $jd): string
{
    return j_fa_num($jd) . ' ' . (J_MONTHS[$jm] ?? '');
}

/** ارقام لاتین → فارسی */
function j_fa_num($v): string
{
    return str_replace(
        ['0','1','2','3','4','5','6','7','8','9'],
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
        (string) $v
    );
}

/** ارقام فارسی/عربی → لاتین */
function j_en_num($v): string
{
    return str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
        (string) $v
    );
}

/** n روز جلوتر */
function j_add_days(int $jy, int $jm, int $jd, int $n): array
{
    [$gy, $gm, $gd] = j_to_greg($jy, $jm, $jd);
    $d = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $gy, $gm, $gd)))
        ->modify(($n >= 0 ? '+' : '') . $n . ' days');
    return j_from_greg((int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j'));
}

/** تاریخ شمسی از رشتهٔ ISO میلادی «2026-10-17» */
function j_from_iso(string $iso): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
        return null;
    }
    return j_from_greg((int) $m[1], (int) $m[2], (int) $m[3]);
}

/** شمسی → رشتهٔ ISO میلادی (برای ذخیره‌سازی و مرتب‌سازی) */
function j_to_iso(int $jy, int $jm, int $jd): string
{
    [$gy, $gm, $gd] = j_to_greg($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}
