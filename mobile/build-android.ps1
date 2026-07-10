# ============================================================
#  AI 伴读 · Android 一键构建脚本（Windows PowerShell）
#  用法：在 mobile/ 目录下，右键「使用 PowerShell 运行」
#        或在终端执行：  .\build-android.ps1
#  前置：已安装 Node.js（含 npm）、能联网
# ============================================================
$ErrorActionPreference = "Stop"

function Check-Command($cmd) {
  if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
    Write-Host "✗ 未找到命令：$cmd。请先安装 Node.js（含 npm），并确认在 PATH 中。" -ForegroundColor Red
    exit 1
  }
}

Write-Host "`n==> [1/5] 检查运行环境" -ForegroundColor Cyan
Check-Command node
Check-Command npm
Check-Command npx
node -v

# 校验 app.json 里的后端地址是否已替换为真实域名
$appJson = Get-Content app.json -Raw | ConvertFrom-Json
if ($appJson.expo.extra.apiBaseUrl -like "*your-backend*") {
  Write-Host "✗ app.json 的 apiBaseUrl 仍是占位符，请先改成真实后端地址（如 https://read.sxmnq.art）。" -ForegroundColor Red
  exit 1
}
Write-Host "✓ apiBaseUrl = $($appJson.expo.extra.apiBaseUrl)" -ForegroundColor Green

Write-Host "`n==> [2/5] 安装依赖（首次较慢，约 1-3 分钟）" -ForegroundColor Cyan
npm install
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "`n==> [3/5] 登录 EAS（浏览器弹出，用 GitHub/Google 账号）" -ForegroundColor Cyan
npx eas login

# 若 projectId 还是占位符，则初始化关联
if ($appJson.expo.extra.eas.projectId -like "00000000*") {
  Write-Host "`n==> [3.5/5] 关联 EAS 项目（自动生成 projectId 写入 app.json）" -ForegroundColor Cyan
  npx eas init
}

Write-Host "`n==> [4/5] 构建 Android APK（EAS 云端编译，约 5-10 分钟）" -ForegroundColor Cyan
Write-Host "    构建完成后终端会显示 .apk 下载链接，也可在 https://expo.dev 下载。" -ForegroundColor Yellow
npx eas build -p android --profile preview
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "`n==> [5/5] 完成" -ForegroundColor Green
Write-Host "  1) 下载上一步的 .apk 文件" -ForegroundColor White
Write-Host "  2) 手机「设置 → 安全 → 未知来源应用」允许当前文件管理器" -ForegroundColor White
Write-Host "  3) 点击 .apk 安装，或连电脑用：adb install <文件名>.apk" -ForegroundColor White
Write-Host "`n如需上架 Google Play，改执行：npx eas build -p android --profile production（产出 .aab）`n" -ForegroundColor Yellow
