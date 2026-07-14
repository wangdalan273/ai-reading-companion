<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Services\LlmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function update(Request $request): JsonResponse
    {
        $presets = AiConfig::presets();
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys($presets))],
            'format' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'base_url' => ['required', 'url', 'max:2048'],
            'model' => ['required', 'string', 'max:255'],
        ]);

        $config = AiConfig::firstOrNew(['user_id' => $request->user()->id]);
        $config->fill([
            'provider' => $data['provider'],
            'format' => $data['provider'] === 'custom' ? $data['format'] : $presets[$data['provider']]['format'],
            'base_url' => rtrim($data['base_url'], '/'),
            'model' => $data['model'],
        ]);
        if (! empty($data['api_key'])) {
            $config->api_key = $data['api_key'];
        }
        $config->save();

        return response()->json($this->payload($request));
    }

    public function test(Request $request, LlmService $llm): JsonResponse
    {
        $result = $llm->testConnection();

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['msg'],
        ], $result['ok'] ? 200 : 422);
    }

    private function payload(Request $request): array
    {
        $presets = AiConfig::presets();
        $config = AiConfig::where('user_id', $request->user()->id)->first();
        $provider = $config?->provider ?: 'openai';
        $preset = $presets[$provider] ?? $presets['openai'];

        return [
            'config' => [
                'provider' => $provider,
                'format' => $config?->format ?: $preset['format'],
                'base_url' => $config?->base_url ?: $preset['base_url'],
                'model' => $config?->model ?: $preset['model'],
                'has_key' => ! empty($config?->api_key),
            ],
            'providers' => $presets,
            'groups' => AiConfig::presetGroups(),
        ];
    }
}
