# 阅伴安装与使用指南

阅伴有两种使用方式。只想阅读时，直接使用线上版最省事；希望数据完全由自己管理、修改源码或部署私人服务时，再选择本地运行。

| 选择 | 适合谁 | 电脑端 | Android 端 |
|---|---|---|---|
| 直接使用线上版 | 普通用户、快速体验 | 打开网页即可 | 下载公开 APK，自动连接线上服务 |
| 下载到本地运行 | 开发者、希望自托管数据的用户 | 在自己的电脑或服务器运行 | 需要 HTTPS 地址，并重新构建连接该地址的 APK |

## 方式一：直接使用线上版（推荐）

### 电脑端

1. 打开 <https://read.sxmnq.art>。
2. 创建账号并登录。
3. 在书架点击“导入书籍”，选择自己拥有合法使用权的 PDF 或 EPUB。
4. 打开书籍开始阅读。阅读位置、书签、划线和笔记会保存到当前账号。

### Android 端

1. 打开 [GitHub 最新版本页面](https://github.com/wangdalan273/ai-reading-companion/releases/latest)。
2. 下载文件名类似 `yueban-v1.1.12-arm64.apk` 的安装包。
3. 在手机文件管理器中打开 APK。若系统拦截，请仅为当前浏览器或文件管理器开启“允许安装未知应用”。
4. 安装后打开“阅伴”，使用与 Web 版相同的账号登录。

公开 APK 已经连接 `https://read.sxmnq.art`，不需要填写 IP、端口或服务器地址。电脑和手机使用同一账号后，书籍、阅读位置、书签、划线、笔记、复习内容和 AI 收藏会通过线上服务同步。

> 如果安装时提示“应用未安装”，先确认设备支持 arm64-v8a，并检查是否安装过签名不同的旧测试包。覆盖安装通常会保留应用数据；只有签名冲突时才需要先卸载旧包。

## 配置自己的 AI 服务

阅伴不在网页或安装包中附带公共 API Key。线上版和本地版都需要用户配置自己的模型服务密钥。

1. 登录后进入“AI 设置”。
2. 选择模型服务商。项目内置 OpenAI、OpenRouter、Claude、Gemini、DeepSeek、Kimi、通义千问、智谱等常见预设，也支持 OpenAI 兼容接口和自定义地址。
3. 填写 API Key，检查 Base URL 和模型名称。
4. 保存并执行连接测试。
5. 测试成功后，即可使用选文问 AI、AI 伴读、章节总结和关系分析。

API Key 会提交到当前阅伴服务端并加密保存，不会写入 Android APK，也不会在读取设置时返回明文。建议使用单独创建、设置消费限额的 Key。不要把 Key 发到 Issue、截图、Agent 提示词或聊天记录中。

## 方式二：下载到本地运行

本地运行意味着 Web、API、数据库和导入的书籍都保存在你自己的电脑或服务器上。它分为两步：先运行 Web 服务；需要手机原生 App 时，再配置手机连接。

### 准备环境

- Git
- PHP 8.3 或更高版本，并启用 SQLite、OpenSSL、cURL 等常用扩展
- Composer
- Node.js 20 或更高版本及 npm
- 只有重新构建 Android APK 时才需要 Android SDK 和 JDK

### 用 Codex 或 Claude Code 一句话安装

先打开 Codex、Claude Code 或其他能够执行终端命令和修改文件的编程 Agent，然后直接发送下面这段话：

```text
请帮我从 https://github.com/wangdalan273/ai-reading-companion 安装并运行阅伴。先检查 Git、PHP 8.3+、Composer、Node.js 20+ 和 npm；缺少依赖时告诉我并给出适合当前系统的安装方法。把项目克隆到合适目录，创建 .env 和 database/database.sqlite，执行 Composer 安装、Laravel APP_KEY 生成、数据库迁移和前端生产构建，然后启动 Laravel 服务。不要把任何 API Key 写入代码或提交到 Git。完成后告诉我本机访问地址、如何注册账号，以及停止和再次启动服务的命令。如果我要连接 Android 手机，请继续询问我准备用临时 HTTPS 隧道还是固定域名，并按“手机连接本地服务”步骤配置 APP_URL、EXPO_PUBLIC_API_ORIGIN 和重新构建 APK；在获得 HTTPS 地址前不要假装手机已经可以连接。
```

如果已经安装了相应 CLI，也可以在准备存放项目的目录中直接运行：

```bash
codex "请按照仓库文档安装并运行 https://github.com/wangdalan273/ai-reading-companion；完成 Web 本地部署，并在需要时继续配置 Android 手机连接。不要写入或输出任何真实 API Key。"
```

或：

```bash
claude "请按照仓库文档安装并运行 https://github.com/wangdalan273/ai-reading-companion；完成 Web 本地部署，并在需要时继续配置 Android 手机连接。不要写入或输出任何真实 API Key。"
```

Agent 应该在执行过程中根据操作系统调整命令。你只需要在它请求安装系统依赖、开放防火墙或配置域名时确认，不要把模型 API Key直接发给 Agent；项目启动后在“AI 设置”页面填写即可。

### 手动安装 Web 与 API

以下命令在项目准备目录中执行：

```bash
git clone https://github.com/wangdalan273/ai-reading-companion.git
cd ai-reading-companion
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
composer run setup
php artisan serve --host=127.0.0.1 --port=8000
```

然后在电脑浏览器打开 <http://127.0.0.1:8000>。

`composer run setup` 会安装 PHP 与前端依赖、复制 `.env.example`、生成 Laravel APP_KEY、执行数据库迁移并构建前端资源。以后再次启动通常只需要：

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

如果修改了前端源码，再运行：

```bash
npm run build
```

## 手机连接本地服务

### 先理解这个限制

手机不能通过 `127.0.0.1` 或 `localhost` 访问电脑，因为这两个地址在手机上代表手机自己。公开 Release APK 的服务器地址又是在构建时写入的，固定连接线上版。因此本地部署要使用原生 Android 客户端时，必须完成以下链路：

```text
Android 手机 → 手机可访问的 HTTPS 地址 → 你的 Laravel 本地服务
```

仅在电脑本地打开网页时，不需要这一部分。

### 第一步：让本地服务可以被手机访问

有两种做法：

1. **临时测试**：使用 Cloudflare Tunnel、ngrok 等工具，把 `http://127.0.0.1:8000` 暴露为临时 HTTPS 地址。
2. **长期使用**：使用自己的域名、有效 HTTPS 证书和反向代理，或配置固定名称的安全隧道。

例如已安装 `cloudflared` 时，可以另开终端运行：

```bash
cloudflared tunnel --url http://127.0.0.1:8000
```

终端会返回类似 `https://example.trycloudflare.com` 的地址。临时隧道每次重启可能变化，地址变化后需要重新构建 APK；长期使用建议配置固定域名。

### 第二步：配置 Laravel 的公开地址

把根目录 `.env` 中的 `APP_URL` 改成刚才获得的 HTTPS 地址：

```dotenv
APP_URL=https://example.trycloudflare.com
```

然后清除配置缓存：

```bash
php artisan config:clear
```

保持 Laravel 服务和 HTTPS 隧道同时运行，并先用手机浏览器打开该 HTTPS 地址，确认登录页能够正常显示。

### 第三步：重新构建连接本地服务的 APK

进入移动端目录并安装依赖：

```bash
cd mobile
npm install
```

Windows PowerShell 示例：

```powershell
$env:ANDROID_HOME="D:\Android\Sdk"
$env:ANDROID_SDK_ROOT=$env:ANDROID_HOME
$env:EXPO_PUBLIC_API_ORIGIN="https://example.trycloudflare.com"
npm run android:standalone
```

macOS 或 Linux 示例：

```bash
export ANDROID_HOME="$HOME/Android/Sdk"
export ANDROID_SDK_ROOT="$ANDROID_HOME"
export EXPO_PUBLIC_API_ORIGIN="https://example.trycloudflare.com"
npm run android:standalone
```

构建成功后的文件位于：

```text
mobile/android/app/build/outputs/apk/release/app-release.apk
```

把这个 APK 传到手机安装。它会连接你在 `EXPO_PUBLIC_API_ORIGIN` 中填写的服务，而不是官方线上服务。正式构建只接受 HTTPS 地址，模型 API Key 绝不能写进 `EXPO_PUBLIC_API_ORIGIN` 或 `mobile/.env.local`。

### 第四步：验证手机连接

1. 手机先用浏览器访问你的 HTTPS 地址，确认网络和证书正常。
2. 安装刚刚重新构建的 APK。
3. 在本地服务中注册一个账号并登录。
4. 导入一本小型 PDF 或 EPUB。
5. 在电脑和手机分别打开，确认书籍、阅读位置、书签和划线能够同步。

线上服务和你的本地服务使用不同数据库，即使邮箱相同也不是同一个账号体系。不要期待线上数据自动出现在本地部署中。

## 一次完整的阅读流程

1. 导入一本 PDF 或 EPUB。
2. 通过目录定位章节，阅读位置会随账号保存。
3. 长按选择文字，划线、写笔记、复制、分享或直接问 AI。
4. 把值得保留的 AI 回答保存到笔记库。
5. 使用章节总结、概念关系、人物关系、论证结构、测验和闪卡复习。
6. 在电脑与手机之间使用同一服务、同一账号继续阅读。

## 常见问题

### 下载公开 APK 后，怎样连接我本地的服务？

不能直接切换。公开 APK 固定连接线上服务。请先为本地服务准备 HTTPS 地址，再设置 `EXPO_PUBLIC_API_ORIGIN` 重新构建 APK。

### 手机和电脑在同一 Wi-Fi，可以直接填写电脑 IP 吗？

手机浏览器可以在防火墙允许时访问 `http://电脑局域网IP:8000`，但正式 APK 的构建脚本要求 HTTPS。为了登录、书籍下载和长期稳定使用，推荐配置 HTTPS 隧道或固定域名。

### 为什么本地网页能打开，手机 App 仍然登录失败？

依次检查：APK 是否使用正确的 `EXPO_PUBLIC_API_ORIGIN` 构建、手机浏览器能否访问该 HTTPS 地址、证书是否有效、Laravel 和隧道是否仍在运行，以及 `.env` 的 `APP_URL` 是否一致。

### Agent 安装完成后为什么没有 AI 回答？

本地服务启动不等于已经配置模型。登录后进入“AI 设置”，填写自己的 API Key、Base URL 和模型名称，并先执行连接测试。

## 当前限制与安全提醒

- 阅伴不提供书源，需要用户自行导入拥有合法使用权的 PDF 或 EPUB。
- Android 版本仍处于公开测试阶段，不同设备、系统版本和 PDF 排版可能存在兼容性差异。
- AI 功能的速度、质量和费用取决于选择的模型服务。
- 不要提交 `.env`、数据库、书籍原文件、API Key、账号密码或签名证书。
- 临时隧道地址等同于把本地服务暴露到互联网；请使用强密码，停止使用后及时关闭隧道。

## 反馈问题

欢迎在 [GitHub Issues](https://github.com/wangdalan273/ai-reading-companion/issues) 提交问题或建议。请尽量附上使用方式（线上或本地）、Web 或 Android、系统版本、设备型号、复现步骤和脱敏后的错误信息。

请勿上传书籍原文件、API Key、账号密码或包含隐私的数据。也欢迎通过 README 中的微信二维码添加“王大懒爱吃肉”交流。
