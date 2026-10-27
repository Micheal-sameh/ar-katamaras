// One-off patch: the app keeps a small rolling offline window and tops it up from /api/readings/range every 2 weeks.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const p = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../public/js/app.js');
let s = fs.readFileSync(p, 'utf8');
const rep = (from, to) => {
  if (!s.includes(from)) throw new Error(`anchor not found: ${from.slice(0, 70)}`);
  s = s.replace(from, () => to);
};

rep("  const STORE_MAX_DAYS = 60;\n", `  // Offline copy kept on the device: a small rolling window, so it never grows (a day is about 40 KB, so this
  // is well under 1 MB per language). Every SYNC_EVERY_DAYS the app asks the server for the next SYNC_DAYS days.
  const KEEP_BEHIND_DAYS = 2;
  const KEEP_AHEAD_DAYS = 16;
  const SYNC_DAYS = 14;
  const SYNC_EVERY_DAYS = 14;
  const SYNC_MIN_AHEAD_DAYS = 7; // also top up early when fewer than this many days are left ahead
`);

const start = s.indexOf('  function pruneStored() {');
const end = s.indexOf('  async function fetchDay(iso, lang) {');
if (start < 0 || end < 0) throw new Error('pruneStored block not found');
s = s.slice(0, start) + `  /** Drops saved days outside the window (a little behind today, up to KEEP_AHEAD_DAYS ahead). */
  function pruneStored() {
    try {
      const oldest = addDays(todayISO(), -KEEP_BEHIND_DAYS);
      const newest = addDays(todayISO(), KEEP_AHEAD_DAYS);
      Object.keys(localStorage).filter((k) => k.startsWith(STORE_PREFIX)).forEach((k) => {
        const iso = k.split(':')[2];
        if (iso < oldest || iso > newest) store.remove(k);
      });
    } catch (e) { /* ignore */ }
  }

  /** How many of the next days are already saved for this language (stops at the first gap). */
  function savedAhead(lang) {
    let n = 0;
    while (n < SYNC_DAYS && readStored(addDays(todayISO(), n), lang)) n++;
    return n;
  }

  let syncing = false;
  /** Tops up the offline window with ONE request for the next two weeks (stored days only: it never makes the server fetch). */
  async function syncAhead(force = false) {
    if (DEMO || syncing || !state.online || Date.now() < pausedUntil) return;
    const lang = state.lang;
    const key = \`syncedAt:\${lang}\`;
    const last = Number(store.get(key, '0')) || 0;
    const due = Date.now() - last >= SYNC_EVERY_DAYS * 86400000;
    if (!force && !due && savedAhead(lang) >= SYNC_MIN_AHEAD_DAYS) return;
    syncing = true;
    try {
      const from = todayISO();
      const res = await fetch(\`\${API_BASE}/range?from=\${from}&days=\${SYNC_DAYS}&lang=\${lang}\${bibleFor(lang) ? \`&bible=\${bibleFor(lang)}\` : ''}\`, { headers: { Accept: 'application/json' }, priority: 'low' });
      if (res.status === 429) { pauseRequests(clamp(parseInt(res.headers.get('Retry-After'), 10) || 30, 5, 120)); return; }
      if (!res.ok) return; // an older server without /range: the app still works day by day
      const body = await res.json();
      const days = body && body.data && Array.isArray(body.data.days) ? body.data.days : [];
      days.forEach((day) => { if (day && day.date) store.set(storeKey(day.date, lang), JSON.stringify(day)); });
      if (days.length) store.set(key, String(Date.now()));
      pruneStored();
    } catch (e) { /* offline or server down: try again next time the app starts */ } finally { syncing = false; }
  }

` + s.slice(end);

rep("  function writeStored(iso, lang, raw) {\n    store.set(storeKey(iso, lang), JSON.stringify(raw));\n    pruneStored();\n  }",
    "  function writeStored(iso, lang, raw) {\n    store.set(storeKey(iso, lang), JSON.stringify(raw));\n    pruneStored();\n  }");
rep("  window.addEventListener('online', () => { state.online = true; render(); load(); });",
    "  window.addEventListener('online', () => { state.online = true; render(); load(); setTimeout(syncAhead, 3000); });");
rep("  load();\n})();", "  load();\n  setTimeout(syncAhead, 3000); // after the first day is on screen\n})();");
fs.writeFileSync(p, s);
console.log('patched');
