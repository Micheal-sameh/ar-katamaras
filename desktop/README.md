# Avarewase Readings: desktop app (Electron)

The same app as the website, in its own window. It needs the Laravel server for the readings;
the address is asked on first start (File > Change server… to change it later).

```
cd desktop
npm install
npm start            # copies ../public into ./app and opens the window
npm run dist:win     # installer in desktop/dist (use dist:mac / dist:linux on those systems)
```

- To preset the server for everyone (installer or script), set the `READINGS_SERVER` environment variable.
- The laravel server already allows cross-origin requests for `api/*`, so nothing changes on the server.
- Viewed days are kept offline by the app itself (localStorage); the service worker is not used.
- If `npm start` fails with "ipcMain undefined", the environment variable `ELECTRON_RUN_AS_NODE` is set; unset it.
- Builds must be made on the target OS (Windows installer on Windows, and so on). Icons come from `public/icons`.

## Where the readings come from
By default the app asks the Katameros API directly (no Laravel server). Every day it loads is kept in its
own cache folder (`days/` inside the app's user-data folder) and the next 14 days (Arabic) are pre-loaded at
start, so already-seen days work offline. File > Use my own server… switches to our Laravel server instead.
