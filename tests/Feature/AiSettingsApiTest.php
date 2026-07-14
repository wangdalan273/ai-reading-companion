<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_can_read_the_same_account_ai_settings_without_receiving_the_key(): void
    {
        $user = User::factory()->create();
        AiConfig::create([
            'user_id' => $user->id,
            'provider' => 'deepseek',
            'format' => 'openai',
            'api_key' => 'sk-secret-value',
            'base_url' => 'https://api.deepseek.com/v1',
            'model' => 'deepseek-chat',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/ai/settings')
            ->assertOk()
            ->assertJsonPath('config.provider', 'deepseek')
            ->assertJsonPath('config.has_key', true)
            ->assertJsonMissingPath('config.api_key')
            ->assertJsonStructure(['config', 'providers', 'groups']);
    }

    public function test_mobile_can_update_account_ai_settings_and_preserve_an_existing_key(): void
    {
        $user = User::factory()->create();
        AiConfig::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'format' => 'openai',
            'api_key' => 'sk-existing',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/ai/settings', [
            'provider' => 'deepseek',
            'format' => 'openai',
            'base_url' => 'https://api.deepseek.com/v1',
            'model' => 'deepseek-chat',
        ])->assertOk()->assertJsonPath('config.provider', 'deepseek');

        $saved = AiConfig::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('sk-existing', $saved->api_key);
        $this->assertSame('deepseek-chat', $saved->model);
    }
}
