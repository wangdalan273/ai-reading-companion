<?php

use App\Models\AiConfig;
use App\Services\LlmService;
use Livewire\Volt\Component;

new class extends Component
{
    public $provider = 'openai';
    public $format = 'openai';
    public $apiKey = '';
    public $hasKey = false;
    public $baseUrl = '';
    public $model = '';
    public $testResult = '';
    public $saved = false;

    public function mount(): void
    {
        $cfg = AiConfig::where('user_id', auth()->id())->first();

        if ($cfg) {
            $this->provider = $cfg->provider;
            $this->format = $cfg->format ?? 'openai';
            $this->hasKey = !empty($cfg->api_key); // 不把明文 key 下发前端（安全）
            $this->baseUrl = $cfg->base_url ?? '';
            $this->model = $cfg->model ?? '';
        } else {
            $p = AiConfig::presets()['openai'];
            $this->baseUrl = $p['base_url'];
            $this->model = $p['model'];
        }
    }

    // 视觉化选择服务商：点卡片即切换并套用预设
    public function selectProvider($key): void
    {
        $this->provider = $key;
        $this->applyPreset();
    }

    // 服务商对应的展示图标
    public function iconFor(string $key): string
    {
        return [
            'openai' => '🤖', 'openrouter' => '🔀', 'claude' => '🧠', 'gemini' => '✨',
            'deepseek' => '🐋', 'moonshot' => '🌙', 'zhipu' => '📘', 'qwen' => '🌟',
            'baichuan' => '🔥', 'minimax' => '🎯', 'doubao' => '🌋', 'yi' => '🌿',
            'stepfun' => '🪜', 'spark' => '🔆', 'qianfan' => '☁️', 'hunyuan' => '💎',
            'cloudbase' => '🌐', 'ollama' => '🦙', 'lmstudio' => '🖥️', 'vllm' => '⚡',
            'custom' => '⚙️',
        ][$key] ?? '🤖';
    }

    // 协议 -> 中文短标签
    public function formatLabel(string $format): string
    {
        return match ($format) {
            'anthropic' => 'Anthropic',
            'gemini' => 'Gemini',
            default => 'OpenAI 兼容',
        };
    }

    // When the provider changes, prefill its known base_url / model / format.
    public function applyPreset(): void
    {
        // 自定义：保留用户已选的协议，只清空 base/model 让其手填。
        if ($this->provider === 'custom') {
            $this->baseUrl = '';
            $this->model = '';

            return;
        }

        $p = AiConfig::presets()[$this->provider] ?? null;
        if ($p) {
            $this->baseUrl = $p['base_url'];
            $this->model = $p['model'];
            $this->format = $p['format'] ?? 'openai';
        }
    }

    public function save(): void
    {
        $this->validate([
            'provider' => 'required|in:'.implode(',', array_keys(\App\Models\AiConfig::presets())),
            'format' => 'required|in:openai,anthropic,gemini',
            'apiKey' => 'nullable|string',
            'baseUrl' => 'required|url',
            'model' => 'required|string|max:255',
        ]);

        $data = [
            'provider' => $this->provider,
            'format' => $this->format,
            'base_url' => rtrim($this->baseUrl, '/'),
            'model' => $this->model,
        ];
        // 仅当用户填写了新 key 才更新；留空则保留原 key（避免明文经前端往返）
        if (! empty($this->apiKey)) {
            $data['api_key'] = $this->apiKey;
        }
        AiConfig::updateOrCreate(
            ['user_id' => auth()->id()],
            $data
        );

        $this->saved = true;
        $this->testResult = '';
    }

    // Save first, then run a lightweight connectivity check server-side.
    public function test(): void
    {
        $this->save();
        $result = (new LlmService())->testConnection();
        $this->testResult = ($result['ok'] ? '✅ ' : '⚠️ ').$result['msg'];
    }
};
?>

<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

    <flux:callout variant="info" class="mb-6">
        你的密钥会以 <b>加密方式只保存在服务器端</b>，绝不会下发到浏览器。浏览器只能收到 AI 流式返回的「字」，拿不到你的 key。
    </flux:callout>

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-[340px_minmax(0,1fr)]">

        <!-- 左：服务商可视化选择 -->
        <section class="self-start rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 lg:sticky lg:top-6">
            <header class="flex items-center gap-2 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                <span class="text-lg">🧩</span>
                <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">选择服务商</h3>
            </header>
            <div class="space-y-5 p-5">
                @foreach (\App\Models\AiConfig::presetGroups() as $group => $keys)
                    <div>
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-400">{{ $group }}</p>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($keys as $key)
                                @php($p = \App\Models\AiConfig::presets()[$key])
                                <button type="button" wire:click="selectProvider('{{ $key }}')"
                                    @class([
                                        'flex w-full items-center gap-2 rounded-xl border p-2.5 text-left transition duration-150',
                                        'border-primary-500 bg-primary-50 ring-2 ring-primary-500/30 dark:border-primary-400 dark:bg-primary-900/30' => $provider === $key,
                                        'border-zinc-200 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/50' => $provider !== $key,
                                    ])>
                                    <span class="text-lg leading-none">{{ $this->iconFor($key) }}</span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $p['label'] }}</span>
                                        <span class="block truncate text-[11px] text-zinc-400">{{ $this->formatLabel($p['format']) }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if ($provider === 'custom')
                    <div class="rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-700">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">API 协议</label>
                        <select wire:model="format" wire:change="applyPreset"
                            class="mt-1.5 block w-full rounded-lg border border-zinc-300 bg-transparent px-3 py-2 text-sm text-zinc-800 outline-none focus:border-primary-500 dark:border-zinc-700 dark:text-zinc-100">
                            <option value="openai">OpenAI 兼容（/chat/completions，Bearer 鉴权）</option>
                            <option value="anthropic">Anthropic / Claude（x-api-key 或 Bearer 鉴权）</option>
                            <option value="gemini">Google Gemini（Key 拼在 URL）</option>
                        </select>
                        <div class="mt-3 space-y-2 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                            @if ($format === 'anthropic')
                                <p>Claude 用 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">x-api-key</code> 头鉴权。Base URL 填 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">https://api.anthropic.com</code>，模型如 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">claude-3-5-sonnet-20241022</code>。</p>
                                <p>腾讯云 <b>CloudBase AI 网关</b>也走此协议，鉴权走 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Authorization: Bearer</code>（应用已自动带上）：Base URL 填你的网关地址，模型填 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">hy3-preview</code>。</p>
                            @elseif ($format === 'gemini')
                                <p>Gemini 把 Key 拼到 URL。Base URL 填 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">https://generativelanguage.googleapis.com/v1beta</code>，模型如 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">gemini-1.5-flash</code>。</p>
                            @else
                                <p>OpenAI 兼容：Base URL 填 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">/v1</code> 根地址（如 <code class="rounded bg-zinc-100 px-1 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">https://api.openai.com/v1</code>）。绝大多数国产/本地大模型都支持。</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <!-- 右：密钥连接 + 保存测试 -->
        <div class="space-y-6">
            <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <header class="flex items-center gap-2 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <span class="text-lg">🔑</span>
                    <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">密钥与连接</h3>
                    @if ($hasKey)
                        <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> 已配置密钥
                        </span>
                    @endif
                </header>
                <div class="space-y-4 p-5">
                    <div>
                        <flux:input type="password" wire:model="apiKey" label="API Key"
                            placeholder="sk-... 或留空使用离线演示模式" />
                        <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">留空则使用「离线演示回复」（无需联网即可体验完整交互）。</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="baseUrl" label="Base URL"
                            placeholder="https://api.openai.com/v1" />
                        <flux:input wire:model="model" label="模型名"
                            placeholder="gpt-4o-mini" />
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <header class="flex items-center gap-2 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <span class="text-lg">💾</span>
                    <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">保存与测试</h3>
                </header>
                <div class="space-y-4 p-5">
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                            <span wire:loading.remove>保存设置</span>
                            <span wire:loading>保存中…</span>
                        </flux:button>
                        <flux:button type="button" wire:click="test" variant="ghost" wire:loading.attr="disabled">
                            <span wire:loading.remove>保存并测试连接</span>
                            <span wire:loading>测试中…</span>
                        </flux:button>
                        @if ($saved)
                            <span class="inline-flex items-center gap-1 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> 已保存
                            </span>
                        @endif
                    </div>

                    @if ($testResult)
                        <flux:callout variant="{{ str_starts_with($testResult, '✅') ? 'success' : 'warning' }}">
                            {{ $testResult }}
                        </flux:callout>
                    @endif
                </div>
            </section>
        </div>
    </form>

    <!-- 服务商一览 -->
    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <header class="flex items-center gap-2 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
            <span class="text-lg">📋</span>
            <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">内置服务商一览</h3>
            <span class="ml-auto text-xs text-zinc-400">选好后自动填入，可改</span>
        </header>
        <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach (\App\Models\AiConfig::presets() as $key => $p)
                @if ($key !== 'custom')
                    <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-800">
                        <div class="flex items-center gap-2">
                            <span class="text-base">{{ $this->iconFor($key) }}</span>
                            <span class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $p['label'] }}</span>
                            <span class="ml-auto shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $this->formatLabel($p['format']) }}</span>
                        </div>
                        <p class="mt-2 truncate font-mono text-[11px] text-zinc-400" title="{{ $p['base_url'] }}">{{ $p['base_url'] }}</p>
                        <p class="truncate font-mono text-[11px] text-zinc-400" title="{{ $p['model'] }}">模型：{{ $p['model'] }}</p>
                    </div>
                @endif
            @endforeach
        </div>
        <p class="border-t border-zinc-100 px-5 py-3 text-xs text-zinc-400 dark:border-zinc-800">
            没有你的厂商？选「自定义 ⚙️」，再在「API 协议」里选 OpenAI / Anthropic / Gemini，手填 Base URL 与模型即可——现已支持 OpenAI 以外的 Claude、Gemini 等。
        </p>
    </section>
</div>
