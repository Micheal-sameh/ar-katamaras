// One-off patch: the app asks for the church access code when the server says it is required, and sends it with every request.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const edit = (file, pairs) => {
  const p = path.join(root, file);
  let s = fs.readFileSync(p, 'utf8');
  for (const [from, to] of pairs) {
    if (!s.includes(from)) throw new Error(`${file}: anchor not found: ${from.slice(0, 70)}`);
    s = s.split(from).join(to);
  }
  fs.writeFileSync(p, s);
};

edit('public/js/app.js', [
  // strings
  ["emptyTitle: 'لا توجد قراءات لهذا اليوم',",
   "codeTitle: 'رمز الكنيسة', codeBody: 'هذا التطبيق لأبناء الكنيسة. اكتب الرمز الذي أعطته لك الكنيسة.', codePlaceholder: 'الرمز', codeSave: 'دخول', codeWrong: 'الرمز غير صحيح. حاول مرة أخرى.', codeBlocked: 'محاولات كثيرة خاطئة. انتظر دقيقة ثم حاول.', codeOffline: 'تعذر التحقق من الرمز الآن. تأكد من الاتصال وحاول مرة أخرى.', codeChange: 'تغيير رمز الكنيسة',\n      emptyTitle: 'لا توجد قراءات لهذا اليوم',"],
  ["emptyTitle: 'No readings for this day',",
   "codeTitle: 'Church code', codeBody: 'This app is for members of the church. Enter the code the church gave you.', codePlaceholder: 'Code', codeSave: 'Enter', codeWrong: 'That code is not right. Try again.', codeBlocked: 'Too many wrong tries. Wait a minute and try again.', codeOffline: 'Could not check the code right now. Check your connection and try again.', codeChange: 'Change church code',\n      emptyTitle: 'No readings for this day',"],
  // the header travels with every request
  ["  const readingUrl = (iso, lang) =>",
   "  // The church access code (when the server asks for one) goes along with every request.\n  const churchCode = () => store.get('churchCode', '');\n  const apiHeaders = () => { const h = { Accept: 'application/json' }; const c = churchCode(); if (c) h['X-Church-Code'] = c; return h; };\n  const readingUrl = (iso, lang) =>"],
  ["{ headers: { Accept: 'application/json' }, priority: 'low' });\n      if (res.status === 429) { pauseRequests(clamp(parseInt(res.headers.get('Retry-After'), 10) || 30, 5, 120)); return; }",
   "{ headers: apiHeaders(), priority: 'low' });\n      if (res.status === 429) { pauseRequests(clamp(parseInt(res.headers.get('Retry-After'), 10) || 30, 5, 120)); return; }\n      if (res.status === 401) return; // the code screen is shown by the next day request"],
  ["    const res = await fetch(readingUrl(iso, lang), { headers: { Accept: 'application/json' } });\n    if (!res.ok) {",
   "    const res = await fetch(readingUrl(iso, lang), { headers: apiHeaders() });\n    if (!res.ok) {"],
  ["const res = await fetch(readingUrl(iso, lang), { headers: { Accept: 'application/json' }, priority: 'low' });",
   "const res = await fetch(readingUrl(iso, lang), { headers: apiHeaders(), priority: 'low' });\n      if (res.status === 401) return;"],
  // a 401 means "ask for the code"
  ["      if (e && e.status === 422) state.status = 'range';",
   "      if (e && e.status === 401) { state.status = 'locked'; state.codeError = ''; render(); return; }\n      if (e && e.status === 422) state.status = 'range';"],
  // the code screen
  ["  function statusBlock() {\n    const tr = t();",
   "  /** Asks for the church code. Shown instead of the readings whenever the server answers 401. */\n  function codeBlock() {\n    const tr = t();\n    return `<div class=\"empty-state code-gate\"><span class=\"empty-icon\">${icon('cross')}</span><strong>${esc(tr.codeTitle)}</strong><p>${esc(tr.codeBody)}</p>\n      <form class=\"code-form\" data-form=\"code\"><input class=\"code-input\" type=\"password\" autocomplete=\"off\" autocapitalize=\"off\" spellcheck=\"false\" aria-label=\"${esc(tr.codePlaceholder)}\" placeholder=\"${esc(tr.codePlaceholder)}\" data-input=\"code\">\n        <button type=\"submit\" class=\"button button--primary\">${esc(tr.codeSave)}</button></form>\n      ${state.codeError ? `<p class=\"code-error\" role=\"alert\">${esc(state.codeError)}</p>` : ''}</div>`;\n  }\n\n  function statusBlock() {\n    const tr = t();\n    if (state.status === 'locked') return codeBlock();"],
  // a way to change the code later
  ["        <div class=\"setting-row\"><div><strong>${esc(tr.headerLabel)}</strong>",
   "        ${churchCode() ? `<div class=\"setting-row\"><div><strong>${esc(tr.codeTitle)}</strong></div>${button({ act: 'changeCode', label: tr.codeChange })}</div>` : ''}\n        <div class=\"setting-row\"><div><strong>${esc(tr.headerLabel)}</strong>"],
  ["    setHeader(el) {",
   "    changeCode() { state.screen = 'today'; state.status = 'locked'; state.codeError = ''; window.scrollTo(0, 0); render(); },\n    setHeader(el) {"],
]);

edit('public/css/styles.css', [
  [".mass-note {", ".mass-note {"],
].slice(0, 0));

fs.appendFileSync(path.join(root, 'public/css/styles.css'), `
/* ---------- church code screen ---------- */
.code-form { display: flex; gap: var(--space-2); width: min(100%, 360px); margin-top: var(--space-4); }
.code-input { flex: 1; min-width: 0; min-height: 44px; padding: 0 var(--space-3); border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface); color: var(--ink); font-size: 16px; }
.code-error { color: var(--accent); font-weight: 600; }
`);

// The mobile wrapper must hand a 401 to the app (the code screen), not treat it as "server down" and use Katameros.
edit('mobile/scripts/sync.js', [
  ["if (res.ok || isRange || res.status === 429 || res.status === 422) return res;", "if (res.ok || isRange || res.status === 429 || res.status === 422 || res.status === 401) return res;"],
]);
console.log('patched');
