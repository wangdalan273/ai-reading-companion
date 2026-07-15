# 阅伴使用指南

阅伴是一款支持 Web 与 Android 的 AI 阅读工具。你可以导入自己拥有合法使用权的 PDF / EPUB，在阅读时划线、标注、续读，并围绕选中文字向 AI 追问。

## 方式一：直接使用 Web 版

打开：<https://read.sxmnq.art>

1. 注册账号并登录。
2. 在书架点击“导入新书”，上传 PDF 或 EPUB。
3. 打开书籍开始阅读；选中文字后可以标注或向 AI 提问。
4. 第一次使用 AI 前，按下文完成“AI 设置”。

Web 版适合电脑阅读，也是 Android 客户端使用的同步服务端。

## 方式二：安装 Android 测试版

前往 [GitHub Releases](https://github.com/wangdalan273/ai-reading-companion/releases/latest) 下载最新 APK，然后在 Android 或鸿蒙兼容设备上侧载安装。

当前 APK 是供公开测试使用的侧载包，不是应用商店正式签名版本。系统可能提示“未知来源应用”，请只从本仓库 Release 页面下载。安装后使用与 Web 版相同的账号，书籍、阅读记录、标注、复习卡片和 AI 设置会通过在线服务同步。

## 配置自己的 AI 服务

阅伴不在安装包中附带公共 API Key。每位用户需要使用自己的模型服务密钥。

1. 登录后进入“AI 设置”。
2. 选择模型服务商。当前内置 OpenAI、OpenRouter、Claude、Gemini、DeepSeek、Kimi、通义千问、智谱等常见预设，也支持 OpenAI 兼容接口和自定义地址。
3. 填写 API Key。
4. 检查 Base URL 与模型名称；使用预设时通常会自动填充。
5. 保存后点击连接测试，再开始选文问 AI、章节总结或知识图谱分析。

API Key 只提交给阅伴服务端，按用户加密保存在数据库中，不会写入 Android 安装包，也不会在读取设置时返回明文。建议使用单独创建、限额较低的 Key，并定期轮换。不要在 Issue、截图或聊天记录中公开密钥。

## 一次完整的阅读流程

1. 导入一本 PDF 或 EPUB。
2. 通过目录定位章节，阅读进度会随账号保存。
3. 选中文字，添加标注或直接追问 AI。
4. 把值得保留的回答加入知识库。
5. 使用章节总结、概念关系、人物关系、论证结构、测验或复习卡片进行深度阅读。
6. 将标注和内容导出为 Markdown，进入自己的写作或知识管理流程。

## 当前限制

- 阅伴暂不提供书源或自动下载功能，需要用户自行导入书籍文件。
- 请只导入你有权阅读和使用的内容，不要传播受版权保护的文件。
- Android 版本仍处于公开测试阶段；不同设备、系统版本和 PDF 排版可能带来兼容性差异。
- AI 功能的速度、质量和费用取决于你选择的模型服务。

## 反馈问题

欢迎在 [GitHub Issues](https://github.com/wangdalan273/ai-reading-companion/issues) 提交问题或建议。为了更快定位，请尽量附上：

- Web 或 Android、系统版本与设备型号
- 操作步骤和实际现象
- 脱敏后的错误提示或截图
- 书籍格式（PDF / EPUB）与大致文件大小

请勿上传书籍原文件、API Key、账号密码或包含隐私的数据。

## 开发者本地运行

### Web 与 API

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate

npm run build
php artisan serve
```

### 移动端开发

```bash
cd mobile
npm install
cp .env.example .env.local
npm run start
```

在 `mobile/.env.local` 中配置移动端可以访问的 HTTPS 服务端地址：

```dotenv
EXPO_PUBLIC_API_ORIGIN=https://reader.example.com
```

`EXPO_PUBLIC_*` 会被编译进客户端，只能放公开的服务端地址，绝不能放模型 API Key。
