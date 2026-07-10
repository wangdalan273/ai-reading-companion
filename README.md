# AI 伴读工具 (Reading Companion)

一个面向深度阅读者的 **AI 伴读工具**：导入 PDF / EPUB，在原文上划线批注，随时向 AI 提问、做章节总结、生成思维导图与概念/人物/论证图谱，并支持苏格拉底式追问与自动测验。数据集中在服务器单库，多端（Web / 手机壳）同一账号登录即天然同步。

> 线上地址：https://read.sxmnq.art
> 技术栈：Laravel 13 + Livewire 4 (Volt) + FluxUI 2 + Tailwind v4 + Alpine.js 3 + SQLite；前端沉浸式体验用原生 CSS / 可选 Three.js；手机端用 Capacitor 6 套壳。

---

## 功能里程碑

| 阶段 | 内容 |
| --- | --- |
| P0–P11 | 阅读器（PDF/EPUB）、划线标注、AI 对话、导出、OCR、自定义 AI Provider |
| P12 | 章节总结 / 思维导图 |
| N5 / N9 / N3 / N7 / N6 | 术语悬停 / 魔鬼辩护 / 概念图谱 / 人物关系 / 论证地图 |
| P13 | 通用 RAG + Obsidian 头等连接器 |
| N12 | 知识库图谱 |
| P15 | 苏格拉底式追问 + 自动测验 |
| UI 整理 | 文本笔记瀑布流 / 分组折叠 |
| 移动端 | Capacitor 安卓壳 + 品牌图标/开机画面（暖色橙→珊瑚渐变） |
| 部署 | 腾讯云轻量（新加坡）上线，强制注册登录，HTTPS |

详细验收与真机测试记录见 [`docs/`](docs/)。

---

## 目录结构（速览）

```
ai-reading-companion/
├─ app/                  # Laravel 应用（Controllers、Models、Services、Providers）
├─ bootstrap/ config/ database/ routes/ tests/   # 标准 Laravel 结构
├─ resources/
│  ├─ views/             # Blade 视图（含 layouts/app.blade.php 全局壳）
│  └─ livewire/          # Volt 组件（全站页面 = Volt 组件，GET-only）
├─ public/               # Web 入口（build 由 Vite 生成，已 gitignore）
├─ docs/                 # 设计稿 / 产品文档 / 部署配置 / 真机测试报告
│  ├─ design/   UI 原型 HTML 对比稿
│  ├─ product/   README/PRD/CHANGELOG/SECURITY 等
│  ├─ deploy/    nginx-companion.conf 生产配置模板
│  └─ references/ 数据结构与架构参考
├─ dev/                  # 开发期自测/探针脚本（smoke_test.php 等）
├─ companion-android/    # Capacitor 安卓壳工程（独立子目录）
└─ .env.example          # 环境变量样例（真实 .env 已 gitignore）
```

---

## 本地开发

```bash
# 1. 安装依赖
composer install
npm install

# 2. 环境配置
cp .env.example .env
php artisan key:generate

# 3. 数据库（SQLite）
touch database/database.sqlite
php artisan migrate

# 4. 前端资源
npm run build          # 生产构建
# 或 npm run dev        # 开发热更新

# 5. 启动
php artisan serve --host=127.0.0.1 --port=8123
```

校验脚本：`./dev/smoke_test.php`（本地 in-process 渲染 `/dashboard`）。

---

## 架构约定（加功能前必读）

来自项目长期记忆，避免踩坑：

- **全站页面 = Volt 组件**，GET-only；提交走 `wire:submit`。
- **内嵌组件只输出单根 `<div>`**，禁止再包 `<x-app-layout>`。
- **绝不用 `wire:navigate`**；普通 `<a>` 整页加载。
- **Alpine.data 须放 `<head>` 普通脚本同步注册**并挂 `window` 兜底。
- epub.js 必须先 `fetch` ArrayBuffer 再 `ePub(buf)`，禁直接传 URL。
- Tailwind 动态类用**完整字面量映射对象**，禁止字符串拼接 class。
- 云端/客户端 Alpine 版本不一致时，`this.$cleanup` 可能不存在 → 用 `init()` + `destroy()` 生命周期替代。
- 中文 PHP 文件优先整文件 `Write` 重写，避免 Edit 追加中文破坏 UTF-8。

---

## 部署（腾讯云轻量）

独立实例 `129.226.83.195`（新加坡），与老项目 `sxmnq.art` 完全隔离：

- webroot `/var/www/companion/public`，PHP-FPM `php8.4-fpm.sock`，独立 SQLite 库。
- **Nginx 致命坑**：静态 `location ~* \.(?:css|js|...)$` 块必须加 `try_files $uri /index.php?$query_string;`，否则 `/livewire/livewire.min.js`、`/flux/flux.min.js` 虚拟路由被截断 404，导致 Livewire 失效、注册/登录“没反应”。
- **上传限制**：改 `/etc/php/8.4/fpm/php.ini` 为 `512M` 并 reload FPM，否则书籍导入被挡死。
- **HTTPS**：certbot 已签 `read.sxmnq.art`，自动续期。
- 生产 Nginx 模板见 [`docs/deploy/nginx-companion.conf`](docs/deploy/nginx-companion.conf)。

---

## 手机壳（Capacitor）

工程在 `companion-android/`：

- `capacitor.config.json` 的 `server.url` 指向 `https://read.sxmnq.art`；`android.allowNavigation` 仅填 `read.sxmnq.art`。
- 图标/开机画面由 `companion-android/gen_assets_ai.py`（AI 生图 + PIL 处理）生成，输出到 `android/app/src/main/res/...`。
- 构建：`unset ACC_PRODUCT_CONFIG_V3 && unset ANDROID_SDK_ROOT && ./gradlew assembleDebug`（仅用 `ANDROID_HOME`）。
- 产物：`android/app/build/outputs/apk/debug/app-debug.apk`（Debug 签名，自用可装；上架需 Release 签名）。

---

## 账号与同步模型

- 强制注册登录；数据全在服务器单库。
- 手机壳与电脑同指 `https://read.sxmnq.art`，**同一账号登录 = 天然同步**，无需额外同步逻辑。

---

## 许可证

见 [`docs/product/LICENSE`](docs/product/LICENSE)。
