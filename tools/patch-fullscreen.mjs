// One-off patch: real full screen for the reading view (web, phone, desktop app).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const pub = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../public');
const edit = (file, pairs) => {
  const p = path.join(pub, file);
  let s = fs.readFileSync(p, 'utf8');
  for (const [from, to] of pairs) {
    if (!s.includes(from)) throw new Error(`${file}: anchor not found: ${from.slice(0, 60)}`);
    s = s.replace(from, () => to);
  }
  fs.writeFileSync(p, s);
};

edit('js/app.js', [
  ["    back: '<path d=\"m15 18-6-6 6-6\" />',",
   "    back: '<path d=\"m15 18-6-6 6-6\" />',\n    expand: '<path d=\"M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5\" />',\n    shrink: '<path d=\"M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5\" />',"],
  ["  // ---------------------------------------------------------------- actions\n",
   `  // ---------------------------------------------------------------- full screen
  // The reading view asks the browser for real full screen (no address bar, no taskbar). It works in browsers,
  // installed apps and the desktop app; iPhone Safari has no such API, so the button is hidden there and the
  // text still fills the whole page.
  const canFullscreen = () => !!(document.fullscreenEnabled && document.documentElement.requestFullscreen);
  const inFullscreen = () => !!document.fullscreenElement;
  function enterFullscreen() {
    if (canFullscreen() && !inFullscreen()) document.documentElement.requestFullscreen({ navigationUI: 'hide' }).catch(() => { /* refused: the page is still full width */ });
  }
  function leaveFullscreen() {
    if (inFullscreen()) document.exitFullscreen().catch(() => {});
  }
  document.addEventListener('fullscreenchange', () => { if (state.focus) render(); });

  // ---------------------------------------------------------------- actions
`],
  ["    focus(el) { state.focus = { sec: Number(el.dataset.sec), tab: Number(el.dataset.i) }; window.scrollTo(0, 0); render(); },",
   "    focus(el) { state.focus = { sec: Number(el.dataset.sec), tab: Number(el.dataset.i) }; enterFullscreen(); window.scrollTo(0, 0); render(); },\n    fullscreen() { if (inFullscreen()) leaveFullscreen(); else enterFullscreen(); },"],
  ["    back() { state.focus = null; stopAuto(); render(); },",
   "    back() { state.focus = null; stopAuto(); leaveFullscreen(); render(); },"],
  ["        \${button({ act: 'autoScroll', variant: 'icon', active: state.auto, title: tr.autoScroll, ic: icon('scroll') })}",
   "        ${canFullscreen() ? button({ act: 'fullscreen', variant: 'icon', active: inFullscreen(), title: tr.fullscreen, ic: icon(inFullscreen() ? 'shrink' : 'expand') }) : ''}\n        ${button({ act: 'autoScroll', variant: 'icon', active: state.auto, title: tr.autoScroll, ic: icon('scroll') })}"],
  ["focus: 'وضع القراءة', back: 'رجوع',", "focus: 'وضع القراءة', fullscreen: 'ملء الشاشة', back: 'رجوع',"],
  ["focus: 'Reading focus', back: 'Back',", "focus: 'Reading focus', fullscreen: 'Full screen', back: 'Back',"],
]);

edit('css/styles.css', [
  [".focus-mode { min-height: 100vh;", ".focus-mode { min-height: 100vh; min-height: 100dvh;"],
  ["@media (max-width: 560px) {\n", "@media (max-width: 560px) {\n  .focus-mode { padding-inline: 14px; }\n"],
]);
console.log('patched');
