<?php
/**
 * رمز یکبارمصرف گوگل (TOTP) — RFC 6238، سازگار با Google Authenticator.
 * بدون وابستگی؛ فقط hash_hmac و base32.
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** base32 → بایت خام */
function totp_b32_decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
    if ($b32 === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($b32) as $c) {
        $i = strpos(TOTP_ALPHABET, $c);
        if ($i === false) {
            continue;
        }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr((int) bindec($byte));
        }
    }
    return $out;
}

/** بایت خام → base32 (بدون padding) */
function totp_b32_encode(string $raw): string
{
    $bits = '';
    foreach (str_split($raw) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_ALPHABET[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

/** کلید تازه (base32) */
function totp_secret(int $bytes = 20): string
{
    return totp_b32_encode(random_bytes($bytes));
}

/** کد ۶ رقمی در زمان مشخص */
function totp_code(string $secret, ?int $time = null, int $period = 30, int $digits = 6): string
{
    $key  = totp_b32_decode($secret);
    if ($key === '') {
        return '';
    }
    $time = $time ?? time();
    $ctr  = pack('N*', 0) . pack('N*', intdiv($time, $period));
    $hash = hash_hmac('sha1', $ctr, $key, true);
    $off  = ord($hash[19]) & 0x0F;
    $num  = ((ord($hash[$off]) & 0x7F) << 24)
          | ((ord($hash[$off + 1]) & 0xFF) << 16)
          | ((ord($hash[$off + 2]) & 0xFF) << 8)
          | (ord($hash[$off + 3]) & 0xFF);
    return str_pad((string) ($num % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** بررسی کد، با پنجرهٔ ±$window دوره (تحمل اختلاف ساعت) */
function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + ($i * 30)), $code)) {
            return true;
        }
    }
    return false;
}

/** آدرس otpauth برای اپ Authenticator */
function totp_uri(string $secret, string $label, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
}
