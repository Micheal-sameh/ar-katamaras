/* The one place to point the app at the Laravel endpoint.
   Browser / PWA served by Laravel:  '/api/readings'  (same origin, no CORS)
   Tauri desktop app:                'http://YOUR-SERVER/api/readings'  (absolute URL)
   ALLOW_DEMO: leave false on a real site. Set true on a developer machine to open /?demo=1 and see
   built-in sample readings without a backend. */
window.APP_CONFIG = { API_BASE: '/api/readings', ALLOW_DEMO: false };
