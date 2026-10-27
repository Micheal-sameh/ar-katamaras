// Second part of the church-code patch: the form handler (checks the typed code with the server, then saves it).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const file = path.join(root, 'public/js/app.js');
let s = fs.readFileSync(file, 'utf8');
const anchor = "  document.addEventListener('input', (e) => {";
if (!s.includes(anchor)) throw new Error('anchor not found');

s = s.replace(anchor, () => `  // The church code form: the typed code is checked with the server first and only kept when it is right.
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest && e.target.closest('[data-form="code"]');
    if (!form) return;
    e.preventDefault();
    const typed = String(form.querySelector('[data-input="code"]').value || '').trim();
    if (!typed) return;
    const tr = t();
    const button = form.querySelector('button');
    button.disabled = true;
    try {
      const res = await fetch(\`\${API_BASE}/access\`, { headers: { Accept: 'application/json', 'X-Church-Code': typed } });
      if (res.ok) {
        store.set('churchCode', typed);
        state.codeError = '';
        state.status = 'loading';
        render();
        load();
        setTimeout(syncAhead, 1500);
        return;
      }
      state.codeError = res.status === 429 ? tr.codeBlocked : tr.codeWrong;
    } catch (err) {
      state.codeError = tr.codeOffline;
    }
    render();
  });
` + anchor);

fs.writeFileSync(file, s);
console.log('patched');
