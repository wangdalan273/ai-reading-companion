<?php

namespace App\Services;

use App\Models\AiConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Streaming LLM proxy for the "旁边问 AI" feature.
 *
 * - Config resolution order: the current user's saved AI setting (DB, encrypted
 *   key) → then the global companion.* config (env). This lets each user bring
 *   their own key from the in-app settings page, with no file editing.
 * - The key stays ONLY on the server; the browser only ever receives tokens.
 * - Provider-agnostic (P11): each AiConfig carries a `format`
 *   (openai | anthropic | gemini). LlmService builds the request and parses the
 *   stream according to that format via small per-provider adapters, so the
 *   "自定义" setting can target non-OpenAI vendors (Claude, Gemini, …).
 * - When no key is configured (or companion.mock is true) it streams a warm,
 *   canned Chinese reply so the full UX is demoable offline.
 */
class LlmService
{
    /**
     * Resolve the effective provider config for the current user.
     *
     * @return array{api_key:string|null, base_url:string, model:string, format:string, mock:bool, system_prompt:string}
     */
    protected function resolveConfig(): array
    {
        $user = Auth::user();
        $db = $user ? AiConfig::where('user_id', $user->id)->first() : null;

        $apiKey  = $db?->api_key ?: config('companion.api_key');
        $baseUrl = $db?->base_url ?: config('companion.base_url');
        $model   = $db?->model ?: config('companion.model');
        $format  = $db?->format ?: $this->envFormat();

        $mock = config('companion.mock') || empty($apiKey);

        return [
            'api_key'       => $apiKey,
            'base_url'      => $baseUrl,
            'model'         => $model,
            'format'        => $format,
            'mock'          => $mock,
            'system_prompt' => config('companion.system_prompt'),
        ];
    }

    /**
     * Map the legacy env provider to a format. The env path historically only
     * supported openai; keep it the default unless explicitly set.
     */
    protected function envFormat(): string
    {
        $p = config('companion.provider', 'openai');

        return in_array($p, ['anthropic', 'gemini'], true) ? $p : 'openai';
    }

    /**
     * CA bundle used to verify outbound HTTPS calls.
     *
     * The managed Windows PHP ships with NO curl.cainfo, so every real API call
     * fails with "cURL error 60: unable to get local issuer certificate". We
     * bundle Mozilla's cacert.pem inside the repo (storage/certs/cacert.pem) and
     * point Guzzle at it explicitly, so the app works regardless of the host's
     * php.ini. Falls back to PHP's default store if the file is missing.
     */
    protected function caBundle(): bool|string
    {
        $path = base_path('storage/certs/cacert.pem');

        return is_file($path) ? $path : true;
    }

    /**
     * Turn a raw transport exception into an actionable Chinese message.
     * Especially: cURL error 60 (missing CA) is an environment issue, not a
     * key/network problem — tell the user that clearly instead of dumping the
     * raw OpenSSL text.
     */
    protected function friendlyError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (stripos($msg, 'cURL error 60') !== false || stripos($msg, 'SSL certificate') !== false) {
            return '本地 PHP 的 cURL 无法验证服务器 SSL 证书（cURL error 60）。'
                .'这是本机环境问题（PHP 缺少 CA 证书包），与密钥无关。'
                .'已加载项目内置 cacert.pem；若仍报此错，请确认 storage/certs/cacert.pem 存在，'
                .'或在 php.ini 设置 curl.cainfo 指向该文件后重启服务。';
        }

        return $msg;
    }

    /**
     * Yield response tokens (strings) as they are produced.
     */
    public function stream(string $userMessage, string $context = '', string $mode = '', string $systemOverride = ''): \Generator
    {
        $cfg = $this->resolveConfig();

        if ($cfg['mock']) {
            yield from $this->mockStream($userMessage, $context, $mode);

            return;
        }

        // 真实流式：包裹 realStream，保证「传输异常」或「返回 200 但 0 token」
        // 两种静默失败都有兜底——面板永远有字可看，绝不只吐一个 [DONE]。
        $yielded = false;
        try {
            foreach ($this->realStream($userMessage, $context, $cfg, $mode, $systemOverride) as $token) {
                $yielded = true;
                yield $token;
            }
        } catch (\Throwable $e) {
            // 传输层异常（SSL/超时/连不上）：降级离线演示 + 明确说明
            yield from $this->mockStream($userMessage, $context, $mode);
            yield "\n\n（说明：模型接口连接异常——".$this->friendlyError($e)
                ."。本次临时用离线演示回复。请检查「AI 设置」里的 base_url、协议与网络。）";

            return;
        }

        // 返回 200 但一个 token 都没产出（典型：协议与端点不匹配，如 openai
        // 格式打 anthropic 端点；或返回了非 SSE 体）→ 降级 + 协议不匹配提示
        if (! $yielded) {
            yield from $this->mockStream($userMessage, $context, $mode);
            $hint = $this->configMismatchHint($cfg);
            yield "\n\n（说明：模型未返回有效内容".($hint !== '' ? '，'.$hint : '')
                ."。本次临时用离线演示回复。请到「AI 设置」核对 base_url 与协议是否匹配。）";
        }
    }

    /**
     * 检测 base_url 与 format 是否明显不匹配，返回一句人话提示（无问题返回空串）。
     * 例如：format=openai 却指向 .../anthropic 端点 —— 代码会按 OpenAI 形状
     * 拼 /chat/completions 并解析 OpenAI 帧，而该端点只认 Anthropic 协议，
     * 结果 200 但 0 token。这条提示能帮用户一眼定位。
     */
    protected function configMismatchHint(array $cfg): string
    {
        $base = strtolower((string) $cfg['base_url']);
        $fmt = $cfg['format'];

        if ($fmt === 'openai' && str_contains($base, 'anthropic')) {
            return '当前协议是 OpenAI，但 base_url 指向 Anthropic 端点（路径含 /anthropic）'
                .'。请改用该厂商的 OpenAI 兼容端点（如智谱 GLM 用 https://open.bigmodel.cn/api/paas/v4），'
                .'或在设置里把协议改为 Anthropic';
        }

        if ($fmt === 'anthropic' && str_contains($base, '/chat/completions')) {
            return '当前协议是 Anthropic，但 base_url 已含 /chat/completions（OpenAI 路径）';
        }

        return '';
    }

    /**
     * Provider-agnostic streaming call. Builds the request per `format`, then
     * reads the SSE body and extracts deltas using the matching shape.
     *
     * Resilience: the provider (e.g. Tencent CloudBase AI gateway) frequently
     * answers with HTTP 429 under free-tier rate limits. We retry with
     * exponential backoff (1s → 2s → 4s) so a *transient* cool-down passes
     * without the user noticing. If 429 persists, we degrade GRACEFULLY to the
     * offline demo stream instead of leaving the panel "dead" — the UX stays
     * alive and a one-line note explains why.
     */
    protected function realStream(string $userMessage, string $context, array $cfg, string $mode = '', string $systemOverride = ''): \Generator
    {
        $msg = $this->buildMessages($cfg, $userMessage, $context, $mode, $systemOverride);
        $req = $this->buildRequest($cfg, $msg, true);

        $maxAttempts = 3;
        $delay = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::withHeaders($req['headers'])
                ->withOptions(['stream' => true, 'verify' => $this->caBundle()])
                ->timeout(60)
                ->post($req['url'], $req['body']);

            if ($response->successful()) {
                yield from $this->readStream($cfg, $response);

                return;
            }

            $status = $response->status();

            // 429 = rate limit / quota: back off and retry (common transient on
            // shared gateways). Don't retry other status codes.
            if ($status === 429 && $attempt < $maxAttempts) {
                sleep($delay);
                $delay *= 2;
                continue;
            }

            if ($status === 429) {
                // Exhausted retries → degrade to offline demo so the panel works.
                yield from $this->mockStream($userMessage, $context, $mode);
                yield "\n\n（说明：模型接口返回 429 限流，本次临时用离线演示回复。稍候重试，或检查「AI 设置」里的 API 额度即可恢复真实回答。）";

                return;
            }

            yield $this->friendlyHttpError($status, $response->body());

            return;
        }
    }

    /**
     * Read an already-200 SSE response body and yield text deltas.
     */
    protected function readStream(array $cfg, $response): \Generator
    {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(1024);

            // SSE frames are separated by a blank line.
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                foreach (explode("\n", $frame) as $line) {
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $payload = trim(substr($line, 5));
                    if ($payload === '[DONE]') {
                        return;
                    }
                    $data = json_decode($payload, true);
                    if (! is_array($data)) {
                        continue;
                    }
                    $delta = $this->extractDelta($cfg['format'], $data);
                    if ($delta !== '' && $delta !== null) {
                        yield $delta;
                    }
                }
            }
        }
    }

    /**
     * Turn a non-2xx HTTP response into an actionable Chinese message.
     * Especially distinguishes 429 (rate limit) from 401/403 (auth) and 400
     * (bad request) so the user isn't misled into thinking their key is wrong
     * when it's actually just throttled.
     */
    protected function friendlyHttpError(int $status, string $body = ''): string
    {
        $detail = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $detail = $decoded['error']['message']
                ?? $decoded['message']
                ?? ($decoded['error'] ?? '');
            if (is_array($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
            }
        }
        $detail = is_string($detail) ? $detail : '';

        if ($status === 429) {
            return '模型接口返回 429（请求过于频繁 / 额度受限）。请稍候再试，或在「AI 设置」检查 API 额度；也可临时改用离线演示模式。';
        }
        if ($status === 401 || $status === 403) {
            return '（鉴权失败 '.$status.'）请检查「AI 设置」里的 API key 与协议是否匹配（CloudBase 等网关选 anthropic 协议、用 Bearer 鉴权）。';
        }
        if ($status === 400) {
            return '（请求被拒 '.$status.'）请检查模型名是否正确、base_url 是否指向该协议的接口。';
        }

        return '（模型调用失败：'.$status.'）'.$detail.' 请检查 API key、模型名、协议与网络，或在设置中改用离线演示模式。';
    }

    /**
     * Build the system prompt + chat messages (OpenAI-style normalized shape).
     * Provider-specific assembly happens in buildRequest().
     */
    protected function buildMessages(array $cfg, string $userMessage, string $context, string $mode = '', string $systemOverride = ''): array
    {
        // 自定义 system prompt 优先（P13 用户自定义人格 / 检索问答指令）。
        $system = $systemOverride !== '' ? $systemOverride : $cfg['system_prompt'];

        if ($context !== '') {
            $system .= "\n\n以下是读者选中的原文，请结合它来回答：\n「{$context}」";
        }

        if ($mode === 'devil') {
            $system .= "\n\n【角色：魔鬼代言人】你现在专门扮演 devil's advocate（挑刺者）。"
                .'你的任务不是附和，而是主动、温和但犀利地找出用户这段话／理解里的漏洞、逻辑跳跃、'
                .'未经证实的假设、或可以反过来想的视角。每次先点出最值得质疑的一点，再给一个相反立场的具体例子。'
                .'聚焦论证本身，不人身攻击；语气像一位较真的朋友，而非考官。';
        }

        if ($mode === 'socratic') {
            $system .= "\n\n【角色：苏格拉底导师】你绝不直接给出答案，只通过一连串有层次的问题，"
                .'引导对方自己思考、自己得出结论。规则：①先肯定对方已经想到的，再抛一个能戳到关键处的问题；'
                .'②问题要具体、指向原文或对方刚说的话，不要空泛；③每次只推进一小步，像剥洋葱；'
                .'④如果对方答上了，就顺着再追问更深一层；如果卡住，给一点线索但不替他说破。'
                .'语气温暖、像在并肩散步聊天，而不是考官审问。';
        }

        return [
            'system' => $system,
            'chat' => [['role' => 'user', 'content' => $userMessage]],
        ];
    }

    /**
     * Build {url, headers, body} for the given provider format.
     * $stream=true produces a streaming request; false is used by testConnection().
     */
    protected function buildRequest(array $cfg, array $msg, bool $stream): array
    {
        $key = $cfg['api_key'];
        $base = rtrim($cfg['base_url'], '/');
        $model = $cfg['model'];

        if ($cfg['format'] === 'anthropic') {
            // The official Anthropic API authenticates with `x-api-key`, but
            // many third-party gateways that expose an Anthropic-compatible
            // endpoint (e.g. Tencent CloudBase AI gateway) authenticate with the
            // standard `Authorization: Bearer` header instead. Sending BOTH
            // keeps the request working against either target — each endpoint
            // simply ignores the header it does not consume. This is exactly
            // why ccswitch (Anthropic Messages 原生) succeeds against CloudBase
            // while a bare `x-api-key` request gets MISSING_CREDENTIALS (401).
            return [
                'url' => $base.'/v1/messages',
                'headers' => [
                    'x-api-key' => $key,
                    'Authorization' => 'Bearer '.$key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'body' => [
                    'model' => $model,
                    'max_tokens' => 1024,
                    'system' => $msg['system'],
                    'messages' => $msg['chat'],
                    'stream' => $stream,
                ],
            ];
        }

        if ($cfg['format'] === 'gemini') {
            $endpoint = $stream ? 'streamGenerateContent?alt=sse&' : 'generateContent?';
            $url = $base.'/models/'.rawurlencode($model).':'.$endpoint.'key='.rawurlencode($key);
            $userText = $msg['system']."\n\n".$msg['chat'][0]['content'];

            return [
                'url' => $url,
                'headers' => ['content-type' => 'application/json'],
                'body' => [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
                    'generationConfig' => ['temperature' => 0.7],
                ],
            ];
        }

        // Default: OpenAI Chat Completions (covers most providers).
        return [
            'url' => $base.'/chat/completions',
            'headers' => [
                'Authorization' => 'Bearer '.$key,
                'content-type' => 'application/json',
            ],
            'body' => [
                'model' => $model,
                'messages' => array_merge([['role' => 'system', 'content' => $msg['system']]], $msg['chat']),
                'stream' => $stream,
                'temperature' => 0.7,
            ],
        ];
    }

    /**
     * Pull the text delta out of a decoded SSE data frame, per provider shape.
     */
    protected function extractDelta(string $format, array $data): string
    {
        if ($format === 'anthropic') {
            if (($data['type'] ?? '') === 'content_block_delta') {
                return $data['delta']['text'] ?? '';
            }
            if (($data['type'] ?? '') === 'error') {
                return '（Claude 错误：'.($data['error']['message'] ?? '未知').'）';
            }

            return '';
        }

        if ($format === 'gemini') {
            if (isset($data['error'])) {
                return '（Gemini 错误：'.($data['error']['message'] ?? '未知').'）';
            }

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        // OpenAI
        return $data['choices'][0]['delta']['content'] ?? '';
    }

    /**
     * Lightweight connectivity check used by the settings page "测试连接" button.
     *
     * @return array{ok:bool, msg:string}
     */
    public function testConnection(): array
    {
        $cfg = $this->resolveConfig();

        if ($cfg['mock']) {
            return ['ok' => true, 'msg' => '当前为离线演示模式（未配置密钥），无需测试。'];
        }

        try {
            $req = $this->buildRequest($cfg, $this->buildMessages($cfg, 'ping', ''), false);
            $resp = Http::withHeaders($req['headers'])
                ->withOptions(['verify' => $this->caBundle()])
                ->timeout(20)
                ->post($req['url'], $req['body']);

            if ($resp->successful()) {
                // 仅 HTTP 200 不够：还要确认模型真的回了内容。协议/端点不匹配时
                // 网关常返回 200 + 空/错误体，旧逻辑会误报「连接成功」。
                $content = $this->extractFull($cfg['format'], $resp->json());
                $hint = $this->configMismatchHint($cfg);

                if (trim((string) $content) === '') {
                    return ['ok' => false, 'msg' => '连接返回但无有效内容'
                        .($hint !== '' ? '，'.$hint : '。请核对 base_url 与协议是否匹配该端点。')];
                }

                return ['ok' => true, 'msg' => '连接成功：'.$cfg['model'].'（'.($cfg['format']).'）可用。'];
            }

            $err = $resp->json('error.message')
                ?? $resp->json('error')
                ?? $resp->json('message')
                ?? '请检查 base_url / key / model / 协议。';

            $code = $resp->json('code');
            $detail = is_string($err) ? $err : json_encode($err, JSON_UNESCAPED_UNICODE);
            if ($code) {
                $detail = '['.$code.'] '.$detail;
            }
            if ($cfg['format'] === 'anthropic' && stripos((string) $code, 'CREDENTIALS') !== false) {
                $detail .= '（CloudBase 等网关用 Authorization: Bearer 鉴权；请确认密钥已正确填入、且协议选 anthropic。）';
            }

            return ['ok' => false, 'msg' => '连接失败（HTTP '.$resp->status().'）：'.$detail];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '连接异常：'.$this->friendlyError($e)];
        }
    }

    /**
     * Non-streaming completion (used by chapter summaries / mind-map aggregation).
     * Reuses the same provider-agnostic request builder as the streaming path.
     */
    public function complete(string $userMessage, string $context = ''): string
    {
        $cfg = $this->resolveConfig();

        if ($cfg['mock']) {
            return $this->mockComplete($userMessage, $context);
        }

        // 注意：complete() 被章节总结/图谱抽取等「大量调用」场景复用，
        // 故重试次数取 2（最多 1 次退避），避免一次生成被几十次重试拖慢；
        // 单次的聊天流式走 realStream（保留 3 次重试 + 离线降级）。
        $maxAttempts = 2;
        $delay = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $msg = $this->buildMessages($cfg, $userMessage, $context);
                $req = $this->buildRequest($cfg, $msg, false);

                $resp = Http::withHeaders($req['headers'])
                    ->withOptions(['verify' => $this->caBundle()])
                    ->timeout(120)
                    ->post($req['url'], $req['body']);

                if (! $resp->successful()) {
                    $status = $resp->status();
                    // 429: back off and retry before giving up.
                    if ($status === 429 && $attempt < $maxAttempts) {
                        sleep($delay);
                        $delay *= 2;
                        continue;
                    }

                    return $this->friendlyHttpError($status, $resp->body());
                }

                $content = $this->extractFull($cfg['format'], $resp->json());

                // 200 但内容为空（协议/端点不匹配的典型表现）→ 给明确提示，
                // 而不是返回空串让调用方（图谱/解释）误判为「成功但无内容」
                if (trim((string) $content) === '') {
                    $hint = $this->configMismatchHint($cfg);

                    return '（模型返回为空'.($hint !== '' ? '，'.$hint : '）');
                }

                return $content;
            } catch (\Throwable $e) {
                if ($attempt < $maxAttempts) {
                    sleep($delay);
                    $delay *= 2;
                    continue;
                }

                return '（模型调用异常：'.$this->friendlyError($e).'）';
            }
        }

        return '（模型调用失败：未知错误）';
    }

    /**
     * Phase 3 — P13 可插拔 embedding（向量检索）。
     *
     * 返回 array<int,array<float>>|null：每个输入文本对应一个向量；
     * 无 key / 非 openai 协议 / 调用失败 → 返回 null，调用方自动降级 BM25。
     * 设计铁律：embedding 是「加分项」，BM25 是「保底项」，向量缺失永不阻断检索。
     */
    public function embeddings(array $texts): ?array
    {
        $cfg = $this->resolveConfig();

        // 离线演示或无密钥 → 无向量，BM25 兜底。
        if ($cfg['mock']) {
            return null;
        }
        // 仅 openai 兼容的 /embeddings 端点支持（绝大多数供应商都兼容）。
        if ($cfg['format'] !== 'openai') {
            return null;
        }

        $base = rtrim($cfg['base_url'], '/');
        $url = $base.'/embeddings';
        $key = $cfg['api_key'];

        try {
            $resp = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
                'content-type' => 'application/json',
            ])->withOptions(['verify' => $this->caBundle()])
                ->timeout(60)
                ->post($url, [
                    'model' => $cfg['model'],
                    'input' => $texts,
                ]);

            if (! $resp->successful()) {
                return null; // 端点不支持 / 失败 → 降级
            }
            $data = $resp->json('data');
            if (! is_array($data)) {
                return null;
            }
            $vecs = [];
            foreach ($data as $item) {
                $vecs[] = $item['embedding'] ?? null;
            }

            return $vecs;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Pull the full assistant content out of a non-streaming response, per format.
     */
    protected function extractFull(string $format, ?array $data): string
    {
        if ($data === null) {
            return '（模型返回为空）';
        }

        if ($format === 'anthropic') {
            if (isset($data['error'])) {
                return '（Claude 错误：'.($data['error']['message'] ?? '未知').'）';
            }
            $blocks = $data['content'] ?? [];

            return collect($blocks)->map(fn ($b) => $b['text'] ?? '')->implode('');
        }

        if ($format === 'gemini') {
            if (isset($data['error'])) {
                return '（Gemini 错误：'.($data['error']['']['message'] ?? '未知').'）';
            }
            $parts = $data['candidates'][0]['content']['parts'] ?? [];

            return collect($parts)->map(fn ($p) => $p['text'] ?? '')->implode('');
        }

        // OpenAI
        if (isset($data['error'])) {
            return '（OpenAI 错误：'.($data['error']['message'] ?? '未知').'）';
        }

        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Canned Chinese summary for the offline demo mode (no API key).
     */
    protected function mockComplete(string $userMessage, string $context): string
    {
        if ($context !== '') {
            $snippet = mb_substr(preg_replace('/\s+/', '', $context), 0, 24, 'UTF-8');

            return "这一节围绕「{$snippet}…」展开。\n\n"
                ."· 核心意思：作者把一件事讲清楚，逻辑顺畅，值得记一笔。\n"
                ."· 关键转折：中间有个小反转，让前面的铺垫有了落点。\n"
                ."· 可联想到：你之前读过的类似主题，可以对照着想。\n"
                ."· 一句收尾：读到这里，大概明白了作者想带你去的方向。\n\n"
                ."（离线演示总结；在「AI 设置」填入密钥后即接真实模型逐章生成。）";
        }

        return "（这是离线演示模式生成的章节要点；填入密钥后将由真实模型逐章总结。）";
    }

    /**
     * Canned warm Chinese reply, streamed token-by-token (offline demo).
     */
    protected function mockStream(string $userMessage, string $context, string $mode = ''): \Generator
    {
        if ($mode === 'socratic') {
            $reply = "（苏格拉底·离线演示）先别急着想答案，我们来聊聊——\n\n"
                ."你刚才说的这段话，最让你自己信服的是哪一句？它为什么站得住脚？\n\n"
                ."再往下想一层：如果有一个人完全不认同，他最可能从哪个点反驳你？你能不能先替他把那个反驳说圆？\n\n"
                ."等你把反方也讲清楚了，你原本的想法里，哪些部分反而更扎实、哪些部分需要打个问号——你自己就能看出来了。\n\n"
                ."（这是离线演示引导；在「AI 设置」填入密钥后，真实模型会针对你的具体内容一层层追问。）";
            $chars = mb_str_split($reply, 3, 'UTF-8');
            foreach ($chars as $piece) {
                yield $piece;
                usleep(8000);
            }

            return;
        }

        if ($mode === 'devil') {
            $reply = "（魔鬼代言人·离线演示）既然要挑刺，那我先抛个问题：\n\n"
                ."你刚才这句话里，有没有可能把「书里写的」当成了「事实本身」？作者说的，未必等于真相，"
                ."也可能只是那一个人的视角。\n\n"
                ."换个立场想：如果站在完全相反的一方，TA 会怎么反驳你？把这个反例写下来，"
                ."你的想法会比现在扎实得多。\n\n"
                ."（这是离线演示挑刺；在「AI 设置」填入密钥后，真实模型会针对你的具体观点犀利发问。）";
            $chars = mb_str_split($reply, 3, 'UTF-8');
            foreach ($chars as $piece) {
                yield $piece;
                usleep(8000);
            }

            return;
        }

        if ($context !== '') {
            $reply = "你选的这句「{$context}」很值得咂摸。\n\n" .
                "用大白话说，它其实在讲：人在某个处境里，心里那点没说出口的波动——" .
                "可能是犹豫，也可能是突然的清醒。作者没直说，但把气氛铺得很足，让你自己体会。\n\n" .
                "顺着这个感觉，可以想想：如果是你处在那个时刻，会怎么选？把它轻轻记下来，" .
                "以后写东西或做决定时，这种「心境」就是很好的素材。\n\n" .
                "（这是离线演示回复；在「AI 设置」里填入你的密钥后即接真实模型。）";
        } else {
            $reply = "你好呀～我是你的伴读助手。\n\n" .
                "在读书时遇到读不懂、或者某句话戳中你的地方，选中那句文字，点「问 AI」或「划线」，" .
                "我就会结合上下文用通俗的话帮你解读，再顺手给点延伸思考。\n\n" .
                "（这是离线演示回复；在「AI 设置」里填入你的密钥后即接真实模型。）";
        }

        // Simulate streaming by emitting small UTF-8 chunks with a tiny pause.
        $chars = mb_str_split($reply, 3, 'UTF-8');
        foreach ($chars as $piece) {
            yield $piece;
            usleep(8000); // ~8ms per chunk, feels like typing
        }
    }

    /**
     * Whether the current user is in offline-demo (no key) mode. Exposed so
     * callers like the "术语悬停" endpoint can return a tailored canned reply
     * instead of the generic chapter-summary mock.
     */
    public function isMockConfig(): bool
    {
        $cfg = $this->resolveConfig();

        return (bool) $cfg['mock'];
    }
}
