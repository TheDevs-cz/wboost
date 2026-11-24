// Captions from photo file names, on the server (feedback K18).
//
// The rule lives in ONE place — `assets/calendar/model/photo-caption.js` (`captionFromFileName`),
// which the browser applies to every photo put on a week. The one-off backfill
// (`app:calendar:backfill-photo-captions`, PHP `CalendarModelBridge::captionsFromFileNames`) pipes
// the original file names here so existing photos get exactly the caption a new upload would.
//
// stdin : { root, names: [string, ...], max }
// stdout: { ok: true, captions: [string | null, ...] }   (same order as `names`)
//       | { ok: false, code, message }

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

try {
    const input = JSON.parse(readFileSync(0, 'utf8'));
    const { captionFromFileName } = await import(pathToFileURL(join(input.root, 'assets/calendar/model/photo-caption.js')).href);
    const max = Number.isInteger(input.max) && input.max > 0 ? input.max : 80;
    const names = Array.isArray(input.names) ? input.names : [];
    process.stdout.write(JSON.stringify({ ok: true, captions: names.map((name) => captionFromFileName(name, max)) }));
} catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, code: 'model_error', message: String((error && error.message) || error) }));
}
