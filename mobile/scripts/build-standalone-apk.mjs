import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const mobileRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const androidRoot = resolve(mobileRoot, 'android');
const appConfigPath = resolve(mobileRoot, 'app.json');
const androidAppBuildPath = resolve(androidRoot, 'app/build.gradle');
const wrapper = process.platform === 'win32' ? 'gradlew.bat' : './gradlew';
const apiOrigin = (process.env.EXPO_PUBLIC_API_ORIGIN || 'https://read.sxmnq.art').replace(/\/+$/, '');
const defaultWindowsSdk = 'D:\\01_DevTools\\android-sdk';
const androidSdk = process.env.ANDROID_HOME || process.env.ANDROID_SDK_ROOT
  || (process.platform === 'win32' && existsSync(defaultWindowsSdk) ? defaultWindowsSdk : undefined);

if (!apiOrigin.startsWith('https://')) {
  console.error(`Release APK requires an HTTPS API origin, received: ${apiOrigin}`);
  process.exit(1);
}

if (!androidSdk) {
  console.error('Android SDK not found. Set ANDROID_HOME or ANDROID_SDK_ROOT.');
  process.exit(1);
}

const appConfig = JSON.parse(readFileSync(appConfigPath, 'utf8'));
const versionName = appConfig.expo?.version;
const versionCode = appConfig.expo?.android?.versionCode;
if (typeof versionName !== 'string' || !Number.isInteger(versionCode) || versionCode < 1) {
  console.error('app.json must define expo.version and a positive expo.android.versionCode.');
  process.exit(1);
}

function syncAndroidVersion() {
  const source = readFileSync(androidAppBuildPath, 'utf8');
  const versionCodePattern = /(\bversionCode\s+)\d+/;
  const versionNamePattern = /(\bversionName\s+)"[^"]+"/;
  if (!versionCodePattern.test(source) || !versionNamePattern.test(source)) {
    throw new Error('Unable to locate Android version fields in app/build.gradle.');
  }
  const next = source
    .replace(versionCodePattern, `$1${versionCode}`)
    .replace(versionNamePattern, `$1"${versionName}"`);
  if (next !== source) writeFileSync(androidAppBuildPath, next, 'utf8');
}

syncAndroidVersion();

console.log(`Release API: ${apiOrigin}`);
console.log(`Android SDK: ${androidSdk}`);
console.log(`Android version: ${versionName} (${versionCode})`);

const architectures = process.env.REACT_NATIVE_ARCHITECTURES || 'arm64-v8a';
const build = spawnSync(wrapper, ['assembleRelease', `-PreactNativeArchitectures=${architectures}`, '--console=plain'], {
  cwd: androidRoot,
  env: {
    ...process.env,
    NODE_ENV: process.env.NODE_ENV || 'production',
    EXPO_PUBLIC_API_ORIGIN: apiOrigin,
    ANDROID_HOME: androidSdk,
    ANDROID_SDK_ROOT: androidSdk,
  },
  shell: process.platform === 'win32',
  stdio: 'inherit',
});

if (build.status !== 0) {
  process.exit(build.status || 1);
}

const apkPath = resolve(androidRoot, 'app/build/outputs/apk/release/app-release.apk');
const verify = spawnSync(process.execPath, [resolve(mobileRoot, 'scripts/verify-standalone-apk.mjs'), apkPath], {
  cwd: mobileRoot,
  stdio: 'inherit',
});

process.exit(verify.status || 0);
