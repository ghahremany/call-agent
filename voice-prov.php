<?php
/**
 * ربات‌ها (درگاه‌های مدل زبانی) و دانشنامه.
 *
 * ربات‌ها در انبار اسناد می‌مانند، پس از پنل قابل افزودن و ویرایش‌اند.
 * اگر یکی خطا بدهد، بعدی امتحان می‌شود (failover).
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

/* ───────────── ربات‌ها ───────────── */

/** ربات‌های فعال، به ترتیب اولویت */
function prov_list(bool $onlyEnabled = true): array
{
    $rows = doc_all('prov');
    if ($onlyEnabled) {
        $rows = array_filter($rows, static fn($r) => !empty($r['enabled']));
    }
    usort($rows, static fn($a, $b) => ((int) ($a['prio'] ?? 100)) <=> ((int) ($b['prio'] ?? 100)));
    return array_values($rows);
}

/**
 * اگر جدول ربات‌ها خالی است، از تنظیمات پر می‌شود.
 * این کار فقط یک بار انجام می‌شود.
 */
function prov_seed(): void
{
    if (doc_all('prov')) {
        return;
    }
    $prio = 10;
    foreach ([
        ['name' => 'Groq', 'base' => (string) vcfg('groq_base'), 'model' => (string) vcfg('groq_model'), 'token' => (string) vcfg('groq_key')],
        ['name' => 'AvalAI', 'base' => (string) vcfg('avalai_base'), 'model' => (string) vcfg('avalai_model'), 'token' => (string) vcfg('avalai_key')],
    ] as $p) {
        if ($p['token'] === '') {
            continue;
        }
        doc_put('prov', $p + [
            'enabled' => 1, 'prio' => $prio, 'ok_count' => 0, 'fail_count' => 0, 'last_err' => '',
        ]);
        $prio += 10;
    }
}

function prov_mark(int $id, bool $ok, string $err = ''): void
{
    $p = doc_get('prov', $id);
    if (!$p) {
        return;
    }
    if ($ok) {
        $p['ok_count']   = (int) ($p['ok_count'] ?? 0) + 1;
        $p['last_err']   = '';
    } else {
        $p['fail_count'] = (int) ($p['fail_count'] ?? 0) + 1;
        $p['last_err']   = mb_substr(preg_replace('/\s+/', ' ', $err) ?? '', 0, 200);
    }
    doc_put('prov', $p);
}

/** آزمون یک ربات با یک پیام کوتاه */
function prov_test(array $p): array
{
    $r = v_chat(
        (string) $p['base'],
        (string) $p['token'],
        (string) $p['model'],
        [['role' => 'user', 'content' => 'فقط بنویس: سلام']],
        false
    );
    return ['ok' => $r['code'] === 200 && $r['text'] !== '', 'code' => $r['code'], 'text' => $r['text']];
}

/* ───────────── دانشنامه ───────────── */

/** آخرین نسخهٔ دانشنامه */
function kb_get(): string
{
    $rows = doc_all('kb');
    if (!$rows) {
        return '';
    }
    usort($rows, static fn($a, $b) => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)));
    return (string) ($rows[0]['content'] ?? '');
}

function kb_save(string $content, string $note = ''): int
{
    return doc_put('kb', [
        'content' => $content,
        'note'    => $note !== '' ? $note : 'ویرایش دستی',
        'at'      => date('c'),
    ]);
}

/** تاریخچهٔ نسخه‌ها (بدون متن کامل، برای فهرست) */
function kb_history(int $limit = 20): array
{
    $rows = doc_all('kb');
    usort($rows, static fn($a, $b) => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)));
    return array_map(static fn($r) => [
        'id'   => (int) $r['id'],
        'note' => (string) ($r['note'] ?? ''),
        'at'   => (string) ($r['at'] ?? ''),
        'len'  => strlen((string) ($r['content'] ?? '')),
    ], array_slice($rows, 0, $limit));
}

/* ───────────── پرامپت سیستمی ───────────── */

/** پرامپت پایه + دانشنامه + فهرست وقت‌های آزاد */
function v_llm_system(array $slots): string
{
    $list = $slots
        ? implode("\n", array_map(static fn($s) => '- ' . $s, $slots))
        : '- NONE CACHED';

    $sys = str_replace('{slots}', $list, (string) vcfg('llm_system'));

    $kb = trim(kb_get());
    if ($kb !== '') {
        $sys .= "\n\n--- CLINIC KNOWLEDGE BASE (authoritative) ---\n"
              . $kb . "\n"
              . "--- END KNOWLEDGE BASE ---\n"
              . "Use the knowledge base above for anything about this clinic: services, hours,\n"
              . "address, rules, tone of voice. If the knowledge base does not cover something,\n"
              . "say a human colleague will explain, and never invent facts.\n";
    }
    return $sys;
}

/* ───────────── فهم جملهٔ کاربر ───────────── */

/**
 * خروجی: ['transcript'=>, 'intent'=>, 'name'=>, 'phone'=>, 'requested_time'=>,
 *          'requested_slot'=>, 'reply'=>, '_provider'=>]
 * اگر همهٔ ربات‌ها خطا بدهند، آرایهٔ خالی برمی‌گردد.
 */
function v_llm_understand(array $slots, array $history, string $heard): array
{
    $messages = [['role' => 'system', 'content' => v_llm_system($slots)]];
    foreach ($history as $m) {
        if (isset($m['role'], $m['content'])) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => "Caller transcript:\n" . $heard];

    prov_seed();

    foreach (prov_list() as $p) {
        $id    = (int) ($p['id'] ?? 0);
        $token = (string) ($p['token'] ?? '');
        $base  = (string) ($p['base'] ?? '');
        $model = (string) ($p['model'] ?? '');
        if ($token === '' || $base === '' || $model === '') {
            continue;
        }

        $res = v_chat($base, $token, $model, $messages, true);
        if ($res['code'] !== 200 || $res['text'] === '') {
            prov_mark($id, false, 'HTTP ' . $res['code']);
            continue;
        }
        $parsed = v_json_loose($res['text']);
        if ($parsed === null) {
            prov_mark($id, false, 'خروجی JSON نبود');
            v_log('LLM(' . ($p['name'] ?? '?') . ') خروجی JSON نبود: ' . substr($res['text'], 0, 200));
            continue;
        }
        prov_mark($id, true);
        $parsed['_provider'] = (string) ($p['name'] ?? '?');
        return $parsed;
    }
    return [];
}
