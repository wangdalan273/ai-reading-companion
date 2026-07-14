# 阅伴：AI 阅读伴侣

一个同时支持 Web 与 Android 的 AI 阅读项目。用户可以导入 PDF / EPUB，记录阅读进度、目录定位、书签与标注，并围绕选中文字持续追问 AI。服务端统一保存书籍、阅读记录、复习卡片和 AI 设置，使电脑端与移动端使用同一账号同步数据。

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

Android 模拟器未配置该变量时默认访问 `http://10.0.2.2:8000`。真机和正式安装包必须在构建前显式设置可访问的 HTTPS 地址。

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
