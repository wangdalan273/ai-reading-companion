<?php

// AI Companion (旁边问 AI) configuration.
// The API key lives ONLY on the server (never exposed to the browser).
// When `mock` is true (or no api_key is set), the backend streams a canned
// Chinese response so the whole flow is demoable without a key / network.

return [
    // 'openai' uses the OpenAI Chat Completions SSE protocol, which most
    // providers (OpenAI, DeepSeek, Moonshot, local llama.cpp, etc.) are compatible with.
    'provider' => env('COMPANION_PROVIDER', 'openai'),

    // Set COMPANION_API_KEY in your .env to use a real model.
    'api_key' => env('COMPANION_API_KEY'),

    // OpenAI-compatible base URL. Override per provider if needed.
    'base_url' => env('COMPANION_BASE_URL', 'https://api.openai.com/v1'),

    'model' => env('COMPANION_MODEL', 'gpt-4o-mini'),

    // Persona for "explain like I'm a novel/personal-growth reader, warm & plain".
    'system_prompt' => env('COMPANION_SYSTEM_PROMPT',
        "你是一位亲切、有温度的伴读助手，用户主要阅读小说、个人成长与学习类书籍。" .
        "请用通俗易懂、有共情的语言回答；避免堆砌术语与代码式讲解。" .
        "当用户给出选中的原文时，先结合上下文通俗解读，再视情况给一点延伸思考或行动建议。"
    ),

    // Force the mock streaming fallback even if a key is present (handy for demos/tests).
    'mock' => env('COMPANION_MOCK', false),

    // "直推 Obsidian": absolute path to your Obsidian vault (or a subfolder).
    // When set and writable, exports are written there as .md files.
    // Leave empty to disable push (Markdown download still works).
    'obsidian_vault_path' => env('COMPANION_OBSIDIAN_VAULT'),
];
