// One-off patch: a Settings choice to keep the reading header visible or let it scroll away.
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
  ["focus: 'وضع القراءة', fullscreen: 'ملء الشاشة',", "focus: 'وضع القراءة', headerLabel: 'شريط عنوان القراءة', headerKeep: 'ثابت', headerScroll: 'يتحرك مع النص', fullscreen: 'ملء الشاشة',"],
  ["focus: 'Reading focus', fullscreen: 'Full screen',", "focus: 'Reading focus', headerLabel: 'Reading header bar', headerKeep: 'Stays visible', headerScroll: 'Scrolls away', fullscreen: 'Full screen',"],
  ["    screen: 'today',\n", "    screen: 'today',\n    stickyHeader: store.get('stickyHeader', '1') !== '0', // keep the reading header visible while scrolling\n"],
  ["        <div class=\"setting-row\"><div><strong>${esc(tr.theme)}</strong>",
   "        <div class=\"setting-row\"><div><strong>${esc(tr.headerLabel)}</strong><small>${esc(state.stickyHeader ? tr.headerKeep : tr.headerScroll)}</small></div>\n          ${seg([['setHeader', tr.headerKeep, state.stickyHeader, '', 'data-v=\"1\"'], ['setHeader', tr.headerScroll, !state.stickyHeader, '', 'data-v=\"0\"']])}</div>\n        <div class=\"setting-row\"><div><strong>${esc(tr.theme)}</strong>"],
  ["    return `<div class=\"focus-mode\">\n      <div class=\"focus-top\">", "    return `<div class=\"focus-mode${state.stickyHeader ? '' : ' header-scrolls'}\">\n      <div class=\"focus-top\">"],
  ["    toggleTheme() {", "    setHeader(el) { state.stickyHeader = el.dataset.v !== '0'; store.set('stickyHeader', state.stickyHeader ? '1' : '0'); render(); },\n    toggleTheme() {"],
]);

edit('css/styles.css', [
  [".focus-top > :last-child {", ".header-scrolls .focus-top { position: static; background: none; backdrop-filter: none; -webkit-backdrop-filter: none; border-bottom-color: transparent; }\n.focus-top > :last-child {"],
]);
console.log('patched');
