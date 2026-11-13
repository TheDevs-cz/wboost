#!/usr/bin/env node
// Development helper: the USER's side of a scan through the app's own web endpoints (there is no
// wizard yet) — the same requests a browser would send, with a logged-in session.
//
//   node dev/app-user.mjs start <projectId> <picture> [--approximate] [--calendar <calendarId>]    POST /project/{id}/ai-scan
//   node dev/app-user.mjs status <scanId>                                 GET  /ai-scan/{id}
//   node dev/app-user.mjs cancel|retry|continue|discard <scanId>          POST /ai-scan/{id}/{action}
//   node dev/app-user.mjs wait <scanId> [--until ready,failed,…] [--timeout 600] [--stage build]
//   node dev/app-user.mjs project <projectId> [--enabled 1|0] [--monthly-limit N|none]   the admin's switch (edit-project page)
//
// WBOOST_APP_URL (default http://localhost:8099); the login comes from WBOOST_LOGIN_FILE — a file with
// `email=…` and `password=…` lines, kept outside the repository. Prints one JSON document.
import { readFileSync } from 'node:fs';
import { basename } from 'node:path';
import { randomUUID } from 'node:crypto';

const TOKEN = 'csrf-token'; // the app's stateless CSRF: the Origin header is what is checked

export class AppUser {
    constructor({ baseUrl = process.env.WBOOST_APP_URL ?? 'http://localhost:8099', loginFile = process.env.WBOOST_LOGIN_FILE } = {}) {
        this.baseUrl = baseUrl.replace(/\/+$/, ''); this.loginFile = loginFile; this.cookies = new Map();
    }
    #cookieHeader() { return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '); }
    async #fetch(path, init = {}) {
        const res = await fetch(this.baseUrl + path, { redirect: 'manual', ...init, headers: { Origin: this.baseUrl, Referer: `${this.baseUrl}/`, Accept: 'application/json', ...(this.cookies.size ? { Cookie: this.#cookieHeader() } : {}), ...(init.headers ?? {}) } });
        for (const c of res.headers.getSetCookie?.() ?? []) { const [pair] = c.split(';'); const i = pair.indexOf('='); const v = pair.slice(i + 1); if (v === 'deleted' || v === '') this.cookies.delete(pair.slice(0, i)); else this.cookies.set(pair.slice(0, i), v); }
        return res;
    }
    async login() {
        if (!this.loginFile) throw new Error('set WBOOST_LOGIN_FILE (a file with email=… and password=… lines)');
        const kv = Object.fromEntries(readFileSync(this.loginFile, 'utf8').split('\n').map((l) => l.trim()).filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')).trim(), l.slice(l.indexOf('=') + 1).trim()]));
        await this.#fetch('/login', { headers: { Accept: 'text/html' } });
        const res = await this.#fetch('/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html' }, body: new URLSearchParams({ _username: kv.email, _password: kv.password, _csrf_token: TOKEN }) });
        const to = res.headers.get('location') ?? '';
        if (res.status !== 302 || /\/login/.test(to)) throw new Error(`login failed (HTTP ${res.status} → ${to || 'no redirect'})`);
        this.email = kv.email;
        return this;
    }
    async #json(res) { const text = await res.text(); try { return { http: res.status, ...JSON.parse(text) }; } catch { return { http: res.status, ok: false, code: 'not_json', body: text.slice(0, 200) }; } }
    async start(projectId, picture, { approximate = false, requestId = randomUUID(), kind = 'template', calendarId = null } = {}) {
        const form = new FormData();
        form.set('picture', new Blob([readFileSync(picture)]), basename(picture));
        form.set('kind', kind); form.set('requestId', requestId); form.set('consent', '1'); form.set('_token', TOKEN);
        if (calendarId) form.set('calendarId', calendarId); // a calendar scan proposes a layout for ONE 7-day calendar
        if (approximate) form.set('options', JSON.stringify({ approximate: true }));
        return this.#json(await this.#fetch(`/project/${projectId}/ai-scan`, { method: 'POST', body: form }));
    }
    async status(scanId) { return this.#json(await this.#fetch(`/ai-scan/${scanId}`)); }
    async act(scanId, action) { return this.#json(await this.#fetch(`/ai-scan/${scanId}/${action}`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ _token: TOKEN }) })); }
    /** An admin's switch on the edit-project page: the entitlement and the monthly limit (null = no limit). */
    async setProjectAiScan(projectId, { enabled = null, monthlyLimit = undefined } = {}) {
        const page = await (await this.#fetch(`/edit-project/${projectId}`, { headers: { Accept: 'text/html' } })).text();
        const token = /id="project-ai-scan"[\s\S]*?name="_token" value="([^"]+)"/.exec(page)?.[1];
        if (!token) return { ok: false, code: 'no_admin_form' };
        const body = new URLSearchParams({ _token: token });
        if (enabled !== null) body.set('enabled', enabled ? '1' : '0');
        if (monthlyLimit !== undefined) body.set('monthly_limit', monthlyLimit === null ? '' : String(monthlyLimit));
        const res = await this.#fetch(`/project/${projectId}/ai-scan/entitlement`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html' }, body });
        return { ok: res.status === 302 || res.status === 303 || res.status === 200, http: res.status };
    }
    /** Polls the status every 2 s (the UI's poll) until one of `until`, or a `stage`, or the timeout. */
    async wait(scanId, { until = ['ready', 'failed', 'stopped', 'refused', 'cancelled'], stage = null, timeoutS = 600, onChange = null } = {}) {
        const end = Date.now() + timeoutS * 1000; let last = '';
        for (;;) {
            const s = await this.status(scanId);
            const scan = s.scan ?? s;
            const key = `${scan.status}/${scan.stage ?? ''}/${scan.note ?? ''}/${scan.waiting ?? ''}`;
            if (key !== last) { last = key; onChange?.(scan); }
            if (until.includes(scan.status) || (stage && scan.status === 'running' && scan.stage === stage)) return s;
            if (Date.now() > end) return { ...s, timedOut: true };
            await new Promise((ok) => setTimeout(ok, 2000));
        }
    }
}

async function main() {
    const [command, ...rest] = process.argv.slice(2);
    const flags = Object.fromEntries(rest.filter((a) => a.startsWith('--')).map((a, i, all) => [a.slice(2), true]));
    const opt = (name, fallback) => { const i = rest.indexOf(`--${name}`); return i >= 0 && rest[i + 1] && !rest[i + 1].startsWith('--') ? rest[i + 1] : fallback; };
    const pos = rest.filter((a, i) => !a.startsWith('--') && !(i > 0 && rest[i - 1].startsWith('--') && !['--approximate'].includes(rest[i - 1])));
    const user = await new AppUser().login();
    let out;
    if (command === 'start') out = await user.start(pos[0], pos[1], { approximate: !!flags.approximate, ...(opt('calendar', null) ? { kind: 'calendar', calendarId: opt('calendar') } : {}) });
    else if (command === 'status') out = await user.status(pos[0]);
    else if (['cancel', 'retry', 'continue', 'discard'].includes(command)) out = await user.act(pos[0], command);
    else if (command === 'wait') out = await user.wait(pos[0], { until: opt('until', 'ready,failed,stopped,refused,cancelled').split(','), stage: opt('stage', null), timeoutS: Number(opt('timeout', 600)), onChange: (s) => console.error(`${new Date().toISOString().slice(11, 19)} ${s.status} ${s.stage ?? ''} ${s.note ?? ''} ${s.waiting ?? ''}`) });
    else if (command === 'project') out = await user.setProjectAiScan(pos[0], { enabled: opt('enabled', null) === null ? null : opt('enabled') === '1', monthlyLimit: opt('monthly-limit', undefined) === undefined ? undefined : (opt('monthly-limit') === 'none' ? null : Number(opt('monthly-limit'))) });
    else { console.error('usage: app-user.mjs start <projectId> <picture> | status|cancel|retry|continue|discard <scanId> | wait <scanId> [--until a,b] [--stage s] [--timeout s]'); process.exit(2); }
    console.log(JSON.stringify(out, null, 2));
    process.exit(out.ok === false ? 1 : 0);
}
if (process.argv[1] && import.meta.url.endsWith(process.argv[1].replace(/^.*\//, '/'))) await main();
