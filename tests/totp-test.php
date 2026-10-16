<?php
declare(strict_types=1);
define('VOICE_APP', 1);
require __DIR__ . '/../voice-totp.php';

$pass = 0; $fail = 0;
function check(string $l, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ✓ %s\n", $l); }
    else { $fail++; printf("  ✗ %s\n      انتظار: %s\n      دریافت: %s\n", $l, var_export($want,true), var_export($got,true)); }
}

echo "\n── base32 ──\n";
check('رمزگذاری/رمزگشایی', totp_b32_decode(totp_b32_encode('سلام')), 'سلام');
check('رمزگشایی نمونه', substr(bin2hex(totp_b32_decode('JBSWY3DPEHPK3PXP')), 0, 12), bin2hex('Hello!'));
check('فاصله و خط تیره نادیده', totp_b32_decode('jbsw y3dp-ehpk 3pxp'), totp_b32_decode('JBSWY3DPEHPK3PXP'));

echo "\n── بردارهای رسمی RFC 6238 (SHA1, 8 رقم) ──\n";
// کلید آزمون RFC: "12345678901234567890" → base32
$secret = totp_b32_encode('12345678901234567890');
foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471',
          1234567890 => '89005924', 2000000000 => '69279037'] as $t => $want) {
    check("زمان $t", totp_code($secret, $t, 30, 8), $want);
}

echo "\n── کد ۶ رقمی و بررسی ──\n";
$s = totp_secret();
$c = totp_code($s);
check('طول کد ۶', strlen($c), 6);
check('کد درست پذیرفته می‌شود', totp_verify($s, $c), true);
check('کد غلط رد می‌شود', totp_verify($s, '000000'), $c === '000000');
check('دورهٔ قبلی پذیرفته می‌شود', totp_verify($s, totp_code($s, time() - 30)), true);
check('دورهٔ دور رد می‌شود', totp_verify($s, totp_code($s, time() - 300)), false);
check('کد کوتاه رد می‌شود', totp_verify($s, '123'), false);
check('کلید تازه یکتا', totp_secret() !== totp_secret(), true);

echo "\n── آدرس otpauth ──\n";
$u = totp_uri('ABCDEF', 'admin', 'منشی صوتی');
check('شروع درست', substr($u, 0, 15), 'otpauth://totp/');
check('شامل secret', strpos($u, 'secret=ABCDEF') !== false, true);

echo "\n";
printf("نتیجه: %d موفق، %d ناموفق\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
