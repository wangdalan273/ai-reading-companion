<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user AI provider settings. The API key uses the `encrypted` cast so it is
 * stored encrypted at rest and only ever decrypted on the server.
 *
 * P11: each config now carries a `format` (openai | anthropic | gemini) so the
 * LlmService can speak the right request/response shape — letting "自定义"
 * target non-OpenAI vendors such as Claude or Gemini.
 */
class AiConfig extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'format', 'api_key', 'base_url', 'model',
        'vault_path', 'note_folder',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Built-in provider presets. Each entry carries a `format` so the
     * LlmService knows which request/response shape to use:
     *   openai    → OpenAI Chat Completions (most providers are compatible)
     *   anthropic → Anthropic Messages API (Claude)
     *   gemini    → Google Gemini Generative Language API
     *
     * `format` defaults to openai because the large majority of vendors
     * (DeepSeek, Moonshot, Zhipu, Qwen, local Ollama/LM Studio/vLLM, …)
     * intentionally expose an OpenAI-compatible endpoint.
     */
    public static function presets(): array
    {
        return [
            // ---------- 国际 ----------
            'openai'     => ['label' => 'OpenAI',                          'format' => 'openai',    'base_url' => 'https://api.openai.com/v1',                         'model' => 'gpt-4o-mini'],
            'openrouter' => ['label' => 'OpenRouter（聚合，含 Claude 等）', 'format' => 'openai',    'base_url' => 'https://openrouter.ai/api/v1',                      'model' => 'openai/gpt-4o-mini'],
            'claude'     => ['label' => 'Anthropic Claude',               'format' => 'anthropic', 'base_url' => 'https://api.anthropic.com',                         'model' => 'claude-3-5-sonnet-20241022'],
            'gemini'     => ['label' => 'Google Gemini',                 'format' => 'gemini',    'base_url' => 'https://generativelanguage.googleapis.com/v1beta',   'model' => 'gemini-1.5-flash'],

            // ---------- 国内（OpenAI 兼容） ----------
            'deepseek'   => ['label' => 'DeepSeek',        'format' => 'openai', 'base_url' => 'https://api.deepseek.com/v1',                       'model' => 'deepseek-chat'],
            'moonshot'   => ['label' => 'Kimi（Moonshot）', 'format' => 'openai', 'base_url' => 'https://api.moonshot.cn/v1',                        'model' => 'moonshot-v1-8k'],
            'zhipu'      => ['label' => '智谱 GLM',         'format' => 'openai', 'base_url' => 'https://open.bigmodel.cn/api/paas/v4',              'model' => 'glm-4-flash'],
            'qwen'       => ['label' => '通义千问（Qwen）', 'format' => 'openai', 'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'model' => 'qwen-plus'],
            'baichuan'   => ['label' => '百川 Baichuan',    'format' => 'openai', 'base_url' => 'https://api.baichuan-ai.com/v1',                    'model' => 'baichuan4'],
            'minimax'    => ['label' => 'MiniMax',          'format' => 'openai', 'base_url' => 'https://api.minimax.chat/v1',                       'model' => 'abab6.5s-chat'],
            'doubao'     => ['label' => '豆包（火山方舟）', 'format' => 'openai', 'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',         'model' => 'doubao-pro-32k'],
            'yi'         => ['label' => '零一万物 Yi',      'format' => 'openai', 'base_url' => 'https://api.lingyiwanwu.com/v1',                    'model' => 'yi-large'],
            'stepfun'    => ['label' => '阶跃星辰 StepFun', 'format' => 'openai', 'base_url' => 'https://api.stepfun.com/v1',                        'model' => 'step-1v8k'],
            'spark'      => ['label' => '讯飞星火 Spark',   'format' => 'openai', 'base_url' => 'https://spark-api-open.xf-yun.com/v1',              'model' => 'generalv3.5'],
            'qianfan'    => ['label' => '百度文心（千帆）', 'format' => 'openai', 'base_url' => 'https://qianfan.baidubce.com/v2',                   'model' => 'ernie-4.0-8k'],
            'hunyuan'    => ['label' => '腾讯混元 Hunyuan', 'format' => 'openai', 'base_url' => 'https://api.hunyuan.cloud.tencent.com/v1',         'model' => 'hunyuan-pro'],
            'cloudbase'  => ['label' => '腾讯云 CloudBase 网关（Anthropic 协议）', 'format' => 'anthropic', 'base_url' => 'https://YOUR-ENV-ID.api.tcloudbasegateway.com/v1/ai/cloudbase', 'model' => 'hy3-preview'],

            // ---------- 本地 / 自托管（OpenAI 兼容） ----------
            'ollama'     => ['label' => 'Ollama（本地）',     'format' => 'openai', 'base_url' => 'http://localhost:11434/v1', 'model' => 'llama3'],
            'lmstudio'   => ['label' => 'LM Studio（本地）',  'format' => 'openai', 'base_url' => 'http://localhost:1234/v1',  'model' => 'local-model'],
            'vllm'       => ['label' => 'vLLM / 本地兼容',    'format' => 'openai', 'base_url' => 'http://localhost:8000/v1', 'model' => 'local-model'],

            // ---------- 自定义（协议由用户在设置页选择） ----------
            'custom'     => ['label' => '自定义（可选协议）', 'format' => 'openai', 'base_url' => '', 'model' => ''],
        ];
    }

    /**
     * Grouping for the settings dropdown (optgroups). Keys must exist in presets().
     */
    public static function presetGroups(): array
    {
        return [
            '国际' => ['openai', 'openrouter', 'claude', 'gemini'],
            '国内（OpenAI 兼容）' => [
                'deepseek', 'moonshot', 'zhipu', 'qwen', 'baichuan',
                'minimax', 'doubao', 'yi', 'stepfun', 'spark', 'qianfan', 'hunyuan',
            ],
            '本地 / 自托管' => ['ollama', 'lmstudio', 'vllm'],
            '云服务网关' => ['cloudbase'],
            '自定义' => ['custom'],
        ];
    }
}
