/* The one place to point the app at the Laravel endpoint.
   Browser / PWA served by Laravel:  '/api/readings'  (same origin, no CORS)
   Tauri desktop app:                'http://YOUR-SERVER/api/readings'  (absolute URL) */
window.APP_CONFIG = { API_BASE: '/api/readings' };
