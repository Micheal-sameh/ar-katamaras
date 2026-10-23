const { app, BrowserWindow, Menu, ipcMain, protocol, shell } = require('electron');
const fs = require('fs');
const path = require('path');
const { KatamerosDirect, ReadingsError } = require('./katameros');

// By default the app asks the Katameros API itself: 'readings://api/...' is answered below, so no
// server of ours is needed. A church can instead point it at its own server (File > Use my own server).
protocol.registerSchemesAsPrivileged([{ scheme: 'readings', privileges: { standard: true, secure: true, supportFetchAPI: true, corsEnabled: true } }]);

const settingsFile = () => path.join(app.getPath('userData'), 'settings.json');

function readSettings() {
  let saved = {};
  try { saved = JSON.parse(fs.readFileSync(settingsFile(), 'utf8')); } catch (e) { /* first run */ }
  // READINGS_SERVER lets an installer or IT script preset a server.
  return { server: process.env.READINGS_SERVER || saved.server || null, languages: saved.languages || null };
}

function writeSettings(patch) {
  const next = { ...readSettings(), ...patch };
  fs.mkdirSync(path.dirname(settingsFile()), { recursive: true });
  fs.writeFileSync(settingsFile(), JSON.stringify(next, null, 2));
}

/** '' = use Katameros directly; null = not a web address. */
function cleanServerUrl(input) {
  const raw = String(input || '').trim().replace(/\/+$/, '');
  if (raw === '') return '';
  let url;
  try { url = new URL(/^https?:\/\//i.test(raw) ? raw : `https://${raw}`); } catch (e) { return null; }
  return ['http:', 'https:'].includes(url.protocol) && url.hostname ? url.origin + url.pathname.replace(/\/+$/, '') : null;
}

let win = null;
let direct = null;

function show() {
  win.loadFile(path.join(__dirname, '../app/index.html'));
}

async function answer(request) {
  const headers = { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' };
  const url = new URL(request.url);
  const iso = decodeURIComponent(url.pathname.split('/').filter(Boolean).pop() || '');
  try {
    const day = await direct.get(iso, url.searchParams.get('lang') || 'ar', Number(url.searchParams.get('bible')) || 0);
    return new Response(JSON.stringify({ data: day }), { headers });
  } catch (e) {
    console.error('[readings]', e.message);
    const status = e instanceof ReadingsError ? e.status : 503;
    const extra = status === 429 ? { 'Retry-After': '30' } : {};
    const message = status === 503 ? 'The readings could not be loaded. Check your internet connection and try again.' : e.message;
    return new Response(JSON.stringify({ message }), { status, headers: { ...headers, ...extra } });
  }
}

/** Keeps the next days in the app's own cache (userData/days), politely, so it also works offline. */
async function warm(days = 14) {
  if (readSettings().server) return; // with our own server, that server does the caching
  const start = new Date();
  for (let i = 0; i < days; i++) {
    const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
    const iso = [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
    if (direct.readCache(iso, 'ar', 0)) continue;
    try { await direct.get(iso, 'ar', 0); } catch (e) { return; /* offline or throttled: try again next start */ }
    await new Promise((r) => setTimeout(r, 1500));
  }
}

function createWindow() {
  win = new BrowserWindow({
    width: 1100, height: 850, minWidth: 380, minHeight: 560,
    title: 'Avarewase Readings',
    backgroundColor: '#faf6ee',
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: false },
  });
  // Links to other sites open in the normal browser, never inside the app.
  win.webContents.setWindowOpenHandler(({ url }) => { shell.openExternal(url); return { action: 'deny' }; });
  win.webContents.on('will-navigate', (event, url) => {
    if (!url.startsWith('file://')) { event.preventDefault(); shell.openExternal(url); }
  });
  show();
}

ipcMain.on('settings:get', (event) => { event.returnValue = readSettings(); });

ipcMain.handle('settings:save-server', (event, input) => {
  const server = cleanServerUrl(input);
  if (server === null) return { error: 'That does not look like a web address.' };
  writeSettings({ server: server || null });
  show();
  return { ok: true };
});

function buildMenu() {
  Menu.setApplicationMenu(Menu.buildFromTemplate([
    { label: 'File', submenu: [
      { label: 'Use my own server…', click: () => win && win.loadFile(path.join(__dirname, 'setup.html')) },
      { label: 'Reload', accelerator: 'CmdOrCtrl+R', click: () => win && show() },
      { type: 'separator' },
      { role: 'quit' },
    ] },
    { label: 'View', submenu: [{ role: 'resetZoom' }, { role: 'zoomIn' }, { role: 'zoomOut' }, { role: 'togglefullscreen' }] },
  ]));
}

if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => { if (win) { if (win.isMinimized()) win.restore(); win.focus(); } });
  app.whenReady().then(() => {
    direct = new KatamerosDirect(path.join(app.getPath('userData'), 'days'));
    protocol.handle('readings', answer);
    buildMenu();
    createWindow();
    warm();
  });
  app.on('window-all-closed', () => app.quit());
}
