# 阅伴：AI 阅读伴侣

一个同时支持 Web 与 Android 的 AI 阅读项目。用户可以导入 PDF / EPUB，记录阅读进度、目录定位、书签与标注，并围绕选中文字持续追问 AI。服务端统一保存书籍、阅读记录、复习卡片和 AI 设置，使电脑端与移动端使用同一账号同步数据。

## 两种使用方式

### 方式一：直接使用线上版（推荐）

不需要安装服务端，也不需要配置手机连接地址：

1. 电脑打开 Web 版：<https://read.sxmnq.art>
2. 手机从 [GitHub Releases](https://github.com/wangdalan273/ai-reading-companion/releases/latest) 下载最新 APK。
3. 电脑和手机登录同一个账号，即可同步书籍、阅读位置、书签、划线、笔记和 AI 收藏。
4. 进入“AI 设置”，填写自己的模型 API Key。

当前 Android 版本：**v1.1.12**（版本号 15，arm64-v8a）。公开 APK 已连接线上服务，安装后无需填写服务器地址。

### 方式二：下载到本地运行

适合希望自己保存数据库、修改代码或部署到私人服务器的用户。可以手动安装，也可以把仓库地址和一句提示词交给 Codex、Claude Code 等编程 Agent 自动完成。

本地 Web 端运行后，电脑可以直接使用；如果还要使用原生 Android 客户端，需要先让本地服务拥有手机可访问的 **HTTPS 地址**，再用该地址重新构建 APK。公开 Release 中的 APK 始终连接线上版，不会自动连接你的本地服务。

完整步骤、Agent 一句话提示词和手机连接方法：[安装与使用指南](docs/GETTING_STARTED.md)。

问题反馈：[GitHub Issues](https://github.com/wangdalan273/ai-reading-companion/issues)

阅伴不提供书源，用户需要自行导入拥有合法使用权的 PDF / EPUB。AI 功能需要用户配置自己的模型服务 API Key。

## v1.1.12 更新

- 修复 Android 端 PDF / EPUB 导入、登录网络配置和本地书籍缓存问题
- 重做移动阅读选文交互，划线、笔记、复制、分享、问 AI 和删除集中在同一操作栏
- 选文问 AI 不再重复请求旧回答；历史对话改为独立管理页，不再遮挡正文
- AI 伴读支持真正的新对话、历史切换和可靠删除，并优化流式回答与 Markdown 阅读显示
- 统一划线、笔记、AI 收藏和复习入口，补充闪卡创建与账号数据同步
- 阅读位置、书签、划线和笔记可以在移动端与电脑端之间同步

完整说明请查看 [v1.1.12 更新日志](docs/releases/v1.1.12.md)。

## 主要功能

- PDF / EPUB 阅读、目录跳转、阅读进度续读、书签和文本标注
- 选文问 AI、连续追问、回答收藏与知识库沉淀
- 章节总结、概念关系、人物关系、论证结构、测验和复习卡片
- Web 与 Android 共用 Laravel API 和账号数据
- 支持 OpenAI 兼容接口及可配置的模型服务

## 技术栈

- 服务端与 Web：PHP 8.3、Laravel 13、Livewire、Volt、Flux UI、Vite
- 移动端：Expo 57、React Native 0.86、TypeScript、React Navigation
- 默认数据库：SQLite；生产环境可按 Laravel 标准配置替换

## 目录结构

```text
app/                 Laravel 业务代码与 API
config/              服务端配置
database/            迁移、工厂与本地 SQLite 位置
resources/           Web 前端资源与 Blade / Livewire 页面
routes/              Web 与移动 API 路由
tests/               PHP 自动化测试
mobile/              Expo / React Native Android 客户端
docs/                产品、架构与开发文档
```

## 本地启动 Web 与 API

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate

npm run build
php artisan serve
```

Windows 环境也可以通过 `artisan-helper.ps1` 运行 Artisan。脚本会优先使用 `PHP_BINARY`，其次使用系统 `PATH` 中的 PHP。

## 启动移动端

```bash
cd mobile
npm install
cp .env.example .env.local
npm run start
```

在 `mobile/.env.local` 中设置公开的服务端地址：

```dotenv
EXPO_PUBLIC_API_ORIGIN=https://reader.example.com
```

未配置该变量时默认访问生产服务 `https://read.sxmnq.art`。若 Android 模拟器需要连接本机开发服务，请显式设置 `EXPO_PUBLIC_API_ORIGIN=http://10.0.2.2:8000`；正式构建脚本只接受 HTTPS 地址。

## AI 配置与密钥安全

AI 服务密钥只应配置在 Laravel 服务器的 `.env` 中，或通过登录后的 AI 设置页面保存到服务端。不要把密钥写入 `mobile/.env*`，因为 `EXPO_PUBLIC_*` 会被编译进客户端安装包。

```dotenv
COMPANION_PROVIDER=openai
COMPANION_API_KEY=
COMPANION_BASE_URL=https://api.openai.com/v1
COMPANION_MODEL=gpt-4o-mini
COMPANION_MOCK=false
```

仓库已忽略 `.env`、SQLite 数据库、导入书籍、证书、签名文件、构建产物和依赖目录。提交前仍应执行密钥扫描，并确认没有真实用户数据进入 Git。

普通用户无需修改配置文件：登录 Web 或 Android 客户端后进入“AI 设置”，选择服务商、填写自己的 API Key，并执行连接测试即可。服务端按用户加密保存密钥，设置接口不会返回明文。

## 验证

```bash
composer test
npm run build

cd mobile
npm run typecheck
npm test
```

## 许可证

参见 [docs/product/LICENSE](docs/product/LICENSE)。

## 联系与交流

欢迎交流 AI 阅读、产品使用建议和问题反馈。微信：**王大懒爱吃肉**。

<img src="docs/assets/wechat-wangdalan.jpg" alt="王大懒爱吃肉微信二维码" width="360">
