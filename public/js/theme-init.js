/* Runs in <head>, before the first paint, so a dark theme never flashes light while app.js loads. */
(function () {
  var dark = false;
  try {
    var t = localStorage.getItem('theme');
    dark = t === 'dark' || (t !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  } catch (e) { /* storage blocked: fall back to the stylesheet's light default */ }
  if (dark) document.documentElement.classList.add('is-dark');
})();
