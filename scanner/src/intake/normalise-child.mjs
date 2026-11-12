// Child process of the intake lane: decodes ONE untrusted file. Usage: normalise-child.mjs <input> <outDir> <json options>
import { writeFile } from 'node:fs/promises';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import sharp from 'sharp';
import { normalise } from './normalise.mjs';

// the kernel should pick this process before the builder when memory runs out
try { writeFileSync('/proc/self/oom_score_adj', '600'); } catch { /* not Linux, or not permitted */ }
sharp.cache(false);
sharp.concurrency(2);

const [input, outDir, json] = process.argv.slice(2);
const options = JSON.parse(json ?? '{}');
try {
    const r = await normalise(input, options);
    if (!r.ok) { console.log(JSON.stringify(r)); process.exit(0); }
    const files = { working: join(outDir, `working.${options.format === 'png' ? 'png' : 'webp'}`), workingPng: join(outDir, 'working.png'), preview: join(outDir, 'preview.webp') };
    await writeFile(files.working, r.working);
    if (files.workingPng !== files.working) await writeFile(files.workingPng, r.workingPng);
    await writeFile(files.preview, r.preview);
    const ru = process.resourceUsage();
    console.log(JSON.stringify({ ok: true, meta: r.meta, files, resources: { peakRssMb: Math.round(ru.maxRSS / 1024), cpuSeconds: +((ru.userCPUTime + ru.systemCPUTime) / 1e6).toFixed(2) } }));
} catch (e) {
    console.log(JSON.stringify({ ok: false, stop: 'unusable_input', detail: 'intake_error', message: String(e?.message ?? e).slice(0, 200) }));
}
