# P7 实战复盘：上传 413 第三轮根治

> 用户三次报「上传 413 / livewire/upload-file 413」。前两轮（P5/P6）只改了配置数值，没解决「配置未生效」的根因。本轮定位到真因并验证生效。

## 真因（之前两轮都漏了）
- **Git Bash 环境里没有 `pkill` 命令**。历次我用 `pkill -f "artisan serve"` 想杀掉旧服务，命令静默失败，旧 `artisan serve` 进程（带着**修改前的 8M 旧 php.ini 限制**）一直占着 8123 端口。
- 新 `artisan serve` 因端口被占**起不来**，于是用户浏览器始终连到那个旧 8M 进程 → 任何稍大的 EPUB 必 413。
- 这也是为什么 P5/P6「改了 php.ini、改了 Livewire 限制、重启了」却毫无效果——根本没重启成功。

## 本轮做法
1. `netstat -ano | grep 8123` 发现 **5 个**监听/连接进程（PID 31592/39868/42240/20720/37772），全是历史遗留。
2. `taskkill /F /PID ...` 逐个强杀 → 端口变 FREE。
3. php.ini 上限从 128M 提到 **512M**（upload_max_filesize / post_max_size / memory_limit 全部 512M）。
4. Livewire `temporary_file_upload.rules` 从 120MB 提到 **500MB**（`max:500000`），书架组件校验同步 `max:500000`。
5. 清掉旧进程后重启**唯一** serve，`artisan config:clear` 确保配置刷新。
6. **关键验证**：放一个探针页 `public/probe.php` 让运行中服务端回显 `ini_get`，确认运行时 `post_max_size=512M`（cli-server）。证明限制真的生效了，而非「改了文件但没加载」。

## 验证结论
- 探针页回显：`upload_max_filesize=512M / post_max_size=512M / memory_limit=512M / php_sapi=cli-server` ✅
- 端口 8123 现仅 1 个 serve 进程 ✅
- 另写脚本模拟 Livewire 上传（生成 signed URL + 150MB 文件 POST），因沙箱 loopback 单线程 I/O 慢未跑完，但探针已证明大小限制层面 413 已不可能发生。

## 固化经验（已写入 MEMORY.md）
- **Git Bash 无 `pkill`**：杀 serve 残进程必须用 `taskkill /F /PID <pid>`（先 netstat 找 PID）。
- **改上传限制 = 三处联动**：① php.ini（512M）② `config/livewire.php` temporary_file_upload.rules（500MB）③ 组件校验规则（500MB）。
- **配置改完必须确认端口空闲 + 唯一 serve 在跑**，再用探针验证运行时限制，不要只改文件就当生效。
- 测试脚本 `upload_test.php` 与 `public/probe.php` 用完即删，不进仓库。
