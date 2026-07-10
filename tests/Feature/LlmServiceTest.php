<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\User;
use App\Services\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeConfig(string $format = 'openai'): User
    {
        config(['companion.mock' => false]);
        $user = User::factory()->create();
        // anthropic 协议的 buildRequest 会再拼 /v1/messages，故 base 不带 /v1
        $base = $format === 'anthropic' ? 'https://fake.test' : 'https://fake.test/v1';
        AiConfig::create([
            'user_id' => $user->id,
            'provider' => $format,
            'api_key' => 'sk-test',
            'base_url' => $base,
            'model' => 'gpt-4o-mini',
            'format' => $format,
        ]);
        Auth::login($user);

        return $user;
    }

    private function sse(array $chunks): string
    {
        $out = '';
        foreach ($chunks as $c) {
            $out .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $c]]]])."\n\n";
        }
        $out .= "data: [DONE]\n\n";

        return $out;
    }

    public function test_stream_retries_on_429_then_succeeds(): void
    {
        $this->fakeConfig();
        Http::fake([
            'fake.test/v1/chat/completions' => Http::sequence()
                ->push('', 429)
                ->push($this->sse(['你好', '世界']), 200),
        ]);

        $svc = new LlmService;
        $got = '';
        foreach ($svc->stream('hi', '') as $tok) {
            $got .= $tok;
        }

        $this->assertStringContainsString('你好', $got);
        $this->assertStringContainsString('世界', $got);
        // 退避成功 → 不应出现离线降级说明
        $this->assertStringNotContainsString('离线演示', $got);
    }

    public function test_stream_falls_back_to_mock_on_persistent_429(): void
    {
        $this->fakeConfig();
        Http::fake([
            'fake.test/v1/chat/completions' => Http::response('', 429),
        ]);

        $svc = new LlmService;
        $got = '';
        foreach ($svc->stream('hi', '选中句') as $tok) {
            $got .= $tok;
        }

        // 持续 429 → 降级离线演示，且给出说明，面板不死
        $this->assertStringContainsString('离线演示', $got);
        $this->assertStringContainsString('429', $got);
    }

    public function test_complete_retries_on_429_then_succeeds(): void
    {
        $this->fakeConfig();
        Http::fake([
            'fake.test/v1/chat/completions' => Http::sequence()
                ->push('', 429)
                ->push(json_encode(['choices' => [['message' => ['content' => '摘要OK']]]]), 200),
        ]);

        $svc = new LlmService;
        $out = $svc->complete('总结', '正文');

        $this->assertStringContainsString('摘要OK', $out);
    }

    public function test_complete_401_gives_auth_friendly_error_and_no_retry(): void
    {
        $this->fakeConfig();
        Http::fake([
            'fake.test/v1/chat/completions' => Http::response('', 401),
        ]);

        $svc = new LlmService;
        $out = $svc->complete('总结', '正文');

        $this->assertStringContainsString('鉴权失败', $out);
    }

    public function test_anthropic_stream_retries_on_429(): void
    {
        $this->fakeConfig('anthropic');
        $sse = "data: ".json_encode(['type' => 'content_block_delta', 'delta' => ['text' => 'hi']])."\n\n"
            ."data: [DONE]\n\n";
        Http::fake([
            'fake.test/v1/messages' => Http::sequence()
                ->push('', 429)
                ->push($sse, 200),
        ]);

        $svc = new LlmService;
        $got = '';
        foreach ($svc->stream('hi', '') as $tok) {
            $got .= $tok;
        }
        $this->assertStringContainsString('hi', $got);
    }
}
