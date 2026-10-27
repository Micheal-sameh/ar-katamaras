// Copies the web app (../public) into ./app, minus what a desktop app doesn't need, and
// writes a config.js that takes the server address from the desktop shell.
const fs = require('fs');
const path = require('path');

const from = path.resolve(__dirname, '../../public');
const to = path.resolve(__dirname, '../app');
const skip = new Set(['sw.js', 'index.php', 'robots.txt', 'favicon.ico', '.htaccess', 'storage', 'hot', 'update']);

fs.rmSync(to, { recursive: true, force: true });
fs.cpSync(from, to, { recursive: true, filter: (src) => !skip.has(path.basename(src)) });

fs.writeFileSync(path.join(to, 'js/config.js'),
`/* Desktop build: the server address comes from the desktop shell (File > Change server). */
window.APP_CONFIG = {
  API_BASE: (window.desktop && window.desktop.apiBase) || '/api/readings',
  LANGUAGES: (window.desktop && window.desktop.languages) || undefined,
  ALLOW_DEMO: false,
};
`);
console.log('Synced web app into desktop/app');
