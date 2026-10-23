const { contextBridge, ipcRenderer } = require('electron');

const settings = ipcRenderer.sendSync('settings:get');

contextBridge.exposeInMainWorld('desktop', {
  apiBase: settings.server ? `${settings.server}/api/readings` : 'readings://api',
  server: settings.server || '',
  languages: settings.languages || null,
  saveServer: (url) => ipcRenderer.invoke('settings:save-server', url),
});
