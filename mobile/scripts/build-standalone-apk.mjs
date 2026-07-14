import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const mobileRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const androidRoot = resolve(mobileRoot, 'android');
const wrapper = process.platform === 'win32' ? 'gradlew.bat' : './gradlew';

const build = spawnSync(wrapper, ['assembleRelease', '--console=plain'], {
  cwd: androidRoot,
  env: { ...process.env, NODE_ENV: process.env.NODE_ENV || 'production' },
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
