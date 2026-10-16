/**
 * پل گوگل‌شیت برای منشی صوتی.
 *
 * فقط اگر می‌خواهی وقت‌ها و رزروها در گوگل‌شیت بمانند (نه در دیتابیس هاست).
 * روش راه‌اندازی:
 *   ۱) شیت خودت را باز کن → Extensions → Apps Script
 *   ۲) این کد را کامل جای‌گذاری کن
 *   ۳) Deploy → New deployment → Web app
 *        Execute as: Me        Who has access: Anyone
 *   ۴) آدرس Web app را کپی کن و در voice-config.php بگذار:
 *        'store_driver' => 'sheets',
 *        'sheets_url'   => 'https://script.google.com/macros/s/.../exec',
 *
 * ستون‌های شیت (ردیف اول، دقیقاً با همین نام‌ها):
 *   slot | name | phone | status
 */

const SHEET_NAME = 'Sheet1';
const HEADERS = ['slot', 'name', 'phone', 'status'];

function sheet_() {
  const ss = SpreadsheetApp.getActive();
  let sh = ss.getSheetByName(SHEET_NAME);
  if (!sh) {
    sh = ss.insertSheet(SHEET_NAME);
  }
  if (sh.getLastRow() === 0) {
    sh.appendRow(HEADERS);
  }
  return sh;
}

/** همهٔ ردیف‌ها به‌صورت آرایه‌ای از شیء */
function rows_(sh) {
  const values = sh.getDataRange().getValues();
  if (values.length < 2) {
    return [];
  }
  const head = values[0].map(function (v) { return String(v).trim().toLowerCase(); });
  const out = [];
  for (let i = 1; i < values.length; i++) {
    const o = { _row: i + 1 };
    for (let c = 0; c < head.length; c++) {
      o[head[c]] = values[i][c];
    }
    out.push(o);
  }
  return out;
}

function json_(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

function doPost(e) {
  try {
    const req = JSON.parse((e && e.postData && e.postData.contents) || '{}');
    const sh = sheet_();
    const op = String(req.op || '');

    if (op === 'free') {
      const limit = Number(req.limit || 6);
      const slots = rows_(sh)
        .filter(function (r) { return String(r.status).trim().toLowerCase() === 'free'; })
        .map(function (r) { return String(r.slot).trim(); })
        .filter(String)
        .slice(0, limit);
      return json_({ ok: true, slots: slots });
    }

    if (op === 'all') {
      return json_({ ok: true, rows: rows_(sh) });
    }

    if (op === 'book') {
      const slot = String(req.slot || '').trim();
      const all = rows_(sh);
      for (let i = 0; i < all.length; i++) {
        if (String(all[i].slot).trim() === slot) {
          if (String(all[i].status).trim().toLowerCase() !== 'free') {
            return json_({ ok: false, err: 'taken' });
          }
          sh.getRange(all[i]._row, 2, 1, 3)
            .setValues([[String(req.name || ''), String(req.phone || ''), 'booked']]);
          return json_({ ok: true });
        }
      }
      return json_({ ok: false, err: 'notfound' });
    }

    if (op === 'add') {
      const slots = Array.isArray(req.slots) ? req.slots : [];
      const have = {};
      rows_(sh).forEach(function (r) { have[String(r.slot).trim()] = true; });
      let added = 0;
      slots.forEach(function (s) {
        s = String(s).trim();
        if (s && !have[s]) {
          sh.appendRow([s, '', '', 'free']);
          have[s] = true;
          added++;
        }
      });
      return json_({ ok: true, added: added });
    }

    // ثبت خلاصهٔ تماس در شیت جداگانه (اختیاری)
    if (op === 'call') {
      const ss = SpreadsheetApp.getActive();
      let log = ss.getSheetByName('calls');
      if (!log) {
        log = ss.insertSheet('calls');
        log.appendRow(['ts', 'sid', 'turns', 'outcome']);
      }
      log.appendRow([new Date(), String(req.sid || ''), Number(req.turns || 0), String(req.outcome || '')]);
      return json_({ ok: true });
    }

    return json_({ ok: false, err: 'unknown_op' });
  } catch (err) {
    return json_({ ok: false, err: String(err) });
  }
}

/** آزمون سریع از داخل Apps Script */
function test_() {
  Logger.log(doPost({ postData: { contents: '{"op":"free","limit":5}' } }).getContent());
}
