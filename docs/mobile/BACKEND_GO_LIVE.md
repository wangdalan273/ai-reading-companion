# AI 伴读 · 后端移动端点上线清单

> **为什么需要这一步**：移动端 App 用 **Bearer Token（Sanctum）** 鉴权，走 `/v1/*` 端点。
> 电脑端「已部署上线」用的是 **session-cookie** 登录（`/api/*` 由 Livewire 占用），
> 两者物理隔离、互不影响。本项目已写好移动端所需代码，并已在本仓库补全 Sanctum 接入，
> 所以服务器上只需 **拉代码 + 装包 + 跑迁移 + 重启** 四条命令即可让移动端点生效，
> **对现有电脑端用户零影响**。

---

## 一、确认前提

- 服务器：`read.sxmnq.art`，webroot `/var/www/companion`，PHP 8.4-FPM + nginx
- 你能 SSH 登录该服务器，且服务器可访问外网（用于 `composer require`）

---

## 二、服务器执行（实际部署方式：SCP 直传，非 git pull）

> ⚠️ **实测更正**：服务器 `/var/www/companion` **不是 git 仓库**，部署走的是「手册式 tar / SCP 直传」，
> 本文档初版写的 `git pull origin main` 在该服务器上**不成立**（已踩坑）。以下为 2026-07-11 验证可用的真实流程。

- 服务器 SSH：`ubuntu@129.226.83.195`（21 端口可达；`read.sxmnq.art` 经 CDN/反代，SSH 直达此 IP）
- webroot：`/var/www/companion`，目录属主 `ubuntu`，可直写
- 本地用 `C:\Users\86155\.ssh\id_ed25519` 密钥登录（known_hosts 已含该 IP）

```bash
# 1. 本地先备份服务器现有文件（出错可秒回滚）
ssh ubuntu@129.226.83.195 \
  "cp /var/www/companion/routes/api.php /var/www/companion/routes/api.php.bak.\$(date +%Y%m%d%H%M)"

# 2. 把本地改好的文件 SCP 上去（示例：本地 routes/api.php）
scp routes/api.php ubuntu@129.226.83.195:/var/www/companion/routes/api.php

# 3. 清 Laravel 路由/配置缓存（让新路由真正生效）
ssh ubuntu@129.226.83.195 \
  "cd /var/www/companion && php artisan route:clear && php artisan config:clear"

# 4. 重启 php-fpm（清 opcache，确保新 PHP 文件被加载）+ 重载 nginx
ssh ubuntu@129.226.83.195 \
  "sudo systemctl restart php8.4-fpm && sudo nginx -t && sudo systemctl reload nginx"
```

### 验证（部署后必做）
```bash
curl -s -o /dev/null -w '%{http_code}\n' https://read.sxmnq.art/api/v1/books
# 期望 401（未带 token 被 sanctum 拦下 = 路由已生效、无 500 致命错误）
```
> 注：api.php 路由经 Laravel 自动加 `api/` 前缀，真实公网路径为 `/api/v1/*`；
> 移动端 client 前缀正是 `/api/v1`，两端一致（见架构记忆）。
> 增量/导入类改动**无需** `composer require` 或 `php artisan migrate`（仅新增端点不改表结构时）。

> **若第 3 步 `migrate` 报 `personal_access_tokens` 表不存在**：
> ```bash
> php artisan vendor:publish --tag=sanctum-migrations
> php artisan migrate
> ```

---

## 三、验证移动端点已生效

```bash
curl -s -X POST https://read.sxmnq.art/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"<你的账号邮箱>","password":"<你的密码>"}'
```

**预期返回**（200）：
```json
{ "token": "<长字符串>", "user": { "id": 1, "name": "...", "email": "..." } }
```

再验证同步端点（带刚才的 token）：
```bash
curl -s "https://read.sxmnq.art/v1/sync" \
  -H "Authorization: Bearer <上一步的 token>"
# 预期返回 {"server_time":"...","books":[],"annotations":[], ...}
```

---

## 四、常见问题

| 现象 | 原因 / 处理 |
|------|------------|
| 500 `Auth guard [sanctum] is not defined` | 本项目已在 `config/auth.php` 注册 `sanctum` guard；若仍报，确认代码已 `git pull` 到位 |
| 404 `/v1/login` | `routes/api.php` 未加载；确认 `bootstrap/app.php` 的 `withRouting` 含 `api: __DIR__.'/../routes/api.php'`（本项目已加） |
| 500 `Class "Laravel\Sanctum\..." not found` | `composer require laravel/sanctum` 未成功；检查服务器外网与 composer 源 |
| 登录成功但 `/v1/*` 仍 401 | 请求未带 `Authorization: Bearer <token>`；移动端已自动注入，无需手动 |
| 电脑端登录异常 | 不应发生——移动端 `/v1` 与电脑端 `/api` 命名空间隔离，互不影响 |

---

**文档版本**：v1 · 2026-07-10
**作用**：让已部署的电脑端后端具备移动端 Token 鉴权能力（仅需服务器端 4 条命令）
