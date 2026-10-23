// Puts the app icon and version details into the Windows exe (electron-builder's own step needs a
// Windows privilege many machines lack, so this does the same job with rcedit).
const path = require('path');
const { rcedit } = require('rcedit');

exports.default = async function afterPack(context) {
  if (context.electronPlatformName !== 'win32') return;
  const exe = path.join(context.appOutDir, `${context.packager.appInfo.productFilename}.exe`);
  await rcedit(exe, {
    icon: path.resolve(__dirname, '../build/icon.ico'),
    'version-string': {
      ProductName: 'Avarewase Readings',
      FileDescription: 'Avarewase Readings',
      CompanyName: 'Avarewase',
      OriginalFilename: 'Avarewase Readings.exe',
    },
    'file-version': context.packager.appInfo.version,
    'product-version': context.packager.appInfo.version,
  });
};
