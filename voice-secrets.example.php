<?php
/**
 * کلیدها — این فایل را به‌عنوان voice-secrets.php کپی کن و پر کن.
 *
 * ⚠️ voice-secrets.php هرگز نباید در گیت برود. در .gitignore هست
 *    و deploy.sh هم آن را آپلود نمی‌کند (فقط دستی).
 */
if (!defined('VOICE_APP')) { http_response_code(403); exit('forbidden'); }

return [
    'el_key'     => 'sk_...',    // ElevenLabs
    'groq_key'   => 'gsk_...',   // Groq
    'avalai_key' => '',          // اختیاری
    'db_pass'    => '',          // رمز دیتابیس
];
