import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';

const apkPath = resolve(process.argv[2] || 'android/app/build/outputs/apk/release/app-release.apk');

if (!existsSync(apkPath)) {
  console.error(`APK not found: ${apkPath}`);
  process.exit(1);
}

const listing = spawnSync('jar', ['tf', apkPath], { encoding: 'utf8' });
if (listing.status !== 0) {
  console.error(listing.stderr || 'Unable to inspect APK with jar.');
  process.exit(listing.status || 1);
}

const entries = new Set(listing.stdout.split(/\r?\n/));
const requiredEntries = ['assets/index.android.bundle'];
const missing = requiredEntries.filter((entry) => !entries.has(entry));

if (missing.length > 0) {
  console.error(`APK is not standalone; missing: ${missing.join(', ')}`);
  process.exit(1);
}

console.log(`Standalone APK verified: ${apkPath}`);
console.log('Bundled JavaScript: assets/index.android.bundle');
