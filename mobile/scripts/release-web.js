// Makes the files to upload for an on-the-fly update: update/bundle-<version>.zip and update/version.json.
// Raise "version" in package.json first (phones only take a version higher than the one they run).
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const AdmZip = require('adm-zip');

const { version } = require('../package.json');
const baseUrl = String(require('../update.config.json').baseUrl || '').replace(/\/+$/, '');
if (!baseUrl) { console.error('Set "baseUrl" in mobile/update.config.json first (the folder you will upload to).'); process.exit(1); }

require('./sync.js');

const out = path.resolve(__dirname, '../update');
fs.mkdirSync(out, { recursive: true });
const zipName = `bundle-${version}.zip`;
const zip = new AdmZip();
zip.addLocalFolder(path.resolve(__dirname, '../www'));
zip.writeZip(path.join(out, zipName));
const checksum = crypto.createHash('sha256').update(fs.readFileSync(path.join(out, zipName))).digest('hex');
fs.writeFileSync(path.join(out, 'version.json'), JSON.stringify({ version, url: `${baseUrl}/${zipName}`, checksum }, null, 2));
console.log(`Upload both files in mobile/update to ${baseUrl}/ (version ${version}).`);
