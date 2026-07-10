# AI 设置连接错误修复 · 真机测试指南（2026-07-08）

## 你遇到的现象
在「AI 设置」里无论选「自定义」还是「混元」，点「保存并测试连接」都报：
```
cURL error 60: SSL certificate OpenSSL verify result:
unable to get local issuer certificate (20) … for https://hy3-…api.tcloudbasegateway.com/v1/ai/cloudbase/chat/completions
```
但你在 ccswitch 里用 **Anthropic Messages（原生）** + `ANTHROPIC_AUTH_TOKEN` + 同一网关地址，就能正常连。

## 根因（第一性原理）
这是**两层**独立问题，叠在一起：

### ① 致命：受管 Windows PHP 没有 CA 证书包
`cURL error 60: unable to get local issuer certificate` 发生在 **TLS 握手阶段的证书验证**，在任何 URL 路径之前。
也就是说——**你 app 里目前所有真实 HTTPS 请求都过不了证书验证**，跟选哪家厂商无关。
- 受管 PHP 的 `php.ini` 里 `curl.cainfo` / `openssl.cafile` 默认是注释掉的 → cURL 没有可信根证书。
- ccswitch 是 **Node** 写的，自带内置 CA 库，所以不受这个坑影响。这正是「ccswitch 能连、app 连不上」的本质差异。

### ② 协议选错：你用的是 OpenAI 格式，网关实际是 Anthropic 协议
- 你 app 里的 `hunyuan` 预设指向**官方混元 API**（`api.hunyuan.cloud.tencent.com`，OpenAI 兼容）。
- 你实际用的是**腾讯云 CloudBase AI 网关**（`api.tcloudbasegateway.com/v1/ai/cloudbase`），它走的是 **Anthropic Messages** 协议（和你 ccswitch 一致）。
- 报错 URL 带 `/chat/completions`，说明 app 当时按 OpenAI 拼的。即使 SSL 修好，OpenAI 格式打到这个网关也会 404/鉴权失败。

## 已修复（三轮优化）
1. **内置 CA 证书包**：用受管 Node（自带 CA）下载 Mozilla 官方 `cacert.pem`（18.9 万字节）到
   `ai-reading-companion/storage/certs/cacert.pem`，随项目走、不依赖本机环境。
2. **php.ini 启用**：`curl.cainfo` / `openssl.cafile` 指向该文件，全局修好所有 cURL。
3. **代码显式 verify + 友好报错**：`LlmService` 三处出站请求（`stream`/`testConnection`/`complete`）都显式 `verify => 该 cacert.pem`；并把 `cURL error 60` 翻译成中文「本机环境问题（PHP 缺 CA 证书），与密钥无关」。
4. **新增 CloudBase 预设**：`AiConfig` 增加「腾讯云 CloudBase 网关（Anthropic 协议）」分组预设（format=anthropic，模型 hy3-preview），并设置页 Anthropic 说明里补充网关填法，避免再选错官方混元预设。

## 自测结论（真 HTTP + 真实网关地址）
用你的真实网关地址 + **anthropic** 协议 + 假 token 请求：
- `→ HTTP 401`（**不再是 cURL error 60**）：证明 TLS 已通、网关已正常响应，仅因 token 是假被拒 → 修复铁证。
- 对照组 `https://api.anthropic.com` → 403，TLS 全局已通。

## 你现在怎么测（真机）
1. 打开 `http://127.0.0.1:8123/enter` 自动登录 → 进「⚙️ AI 设置」。
2. 在「AI 服务商」下拉里选 **「腾讯云 CloudBase 网关（Anthropic 协议）」**（在「云服务网关」分组下）。
   - 它会自动填：协议=Anthropic、模型=`hy3-preview`。
   - Base URL 留空，请填入你的网关地址：`https://hy3-d8gfx6nztf84ee6cf.api.tcloudbasegateway.com/v1/ai/cloudbase`
3. API Key 填你在 ccswitch 里用的那个 token。
4. 点「**保存并测试连接**」：
   - **预期成功**：提示「连接成功：hy3-preview（anthropic）可用。」
   - 若仍报 `cURL error 60`：说明 `storage/certs/cacert.pem` 丢失，重跑下载或检查 php.ini 后重启服务。
   - 若报 `401/403`：密钥填错或网关 token 无效，与证书无关（证书已通）。
5. 连接成功后，去 `/book/15/graph` 点「🤖 生成 / 重新生成」即可用真实混元模型抽概念图谱；阅读器「问 AI」也会走真实模型。

> 备选：若不想用 CloudBase 预设，也可选「自定义」→「API 协议」选 **Anthropic / Claude**，填同样的 Base URL 与模型，效果一致。

## 举一反三（已固化为项目约定）
- 受管 PHP 默认 `curl.cainfo` 为空 → 所有出站 HTTPS 必报 cURL 60；本项目用内置 `cacert.pem` + 代码 `verify` 根治，新 clone 需保留该文件（或重设 php.ini）。
- 任何「app 连不上但 ccswitch/Node 能连」的现象，优先怀疑：① CA 证书；② 协议/URL 拼接是否与对方一致（OpenAI 拼 `/chat/completions`、Anthropic 拼 `/v1/messages`）。
