/* On-the-fly updates: asks {baseUrl}/version.json and, if it names a newer web version than the one
   running, downloads that bundle and uses it from the next launch. Generated into www/js/updater.js. */
(function () {
  var BASE_URL = '__BASE_URL__';
  var BUILT_IN = '__BUILT_IN__';
  var cap = window.Capacitor;
  var updater = cap && cap.Plugins && cap.Plugins.CapacitorUpdater;
  if (!updater) return; // plain browser

  // Confirms this bundle started fine; without it the plugin rolls back to the previous one.
  updater.notifyAppReady();
  if (!BASE_URL) return;

  var parse = function (v) { return String(v).split('-')[0].split('.').map(function (n) { return parseInt(n, 10) || 0; }); };
  var newer = function (a, b) {
    var x = parse(a), y = parse(b);
    for (var i = 0; i < 3; i++) { if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) > (y[i] || 0); }
    return false;
  };

  updater.current().then(function (info) {
    var running = info.bundle && info.bundle.version && info.bundle.version !== 'builtin' ? info.bundle.version : BUILT_IN;
    return fetch(BASE_URL + '/version.json?t=' + Date.now(), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (remote) {
        if (!remote || !remote.version || !remote.url || !newer(remote.version, running)) return;
        return updater.download({ url: remote.url, version: remote.version, checksum: remote.checksum || undefined })
          .then(function (bundle) { return updater.next({ id: bundle.id }); });
      });
  }).catch(function () { /* offline or host down: try again next launch */ });
})();
