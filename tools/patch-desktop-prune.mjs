import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../desktop/src');
const edit = (file, from, to) => {
  const p = path.join(root, file);
  const s = fs.readFileSync(p, 'utf8');
  if (!s.includes(from)) throw new Error(`${file}: anchor not found`);
  fs.writeFileSync(p, s.replace(from, () => to));
};
edit('katameros.js', '  readCache(iso, lang, bibleId) {', `  /** Deletes saved days outside a small window (2 days back, 16 ahead), so the folder never grows. */
  prune(today = new Date()) {
    const iso = (n) => { const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + n); return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-'); };
    const oldest = iso(-2), newest = iso(16);
    for (const name of fs.readdirSync(this.cacheDir)) {
      const day = /^(\d{4}-\d{2}-\d{2})\./.exec(name);
      if (day && (day[1] < oldest || day[1] > newest)) { try { fs.unlinkSync(path.join(this.cacheDir, name)); } catch (e) { /* in use: next start */ } }
    }
  }

  readCache(iso, lang, bibleId) {`);
edit('main.js', "  if (readSettings().server) return; // with our own server, that server does the caching\n", "  if (readSettings().server) return; // with our own server, that server does the caching\n  direct.prune();\n");
console.log('ok');
