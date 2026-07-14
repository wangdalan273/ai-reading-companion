import { describe, expect, it } from 'vitest';
import { createAiSettingsDraft, describeAiSettingsFailure, selectAiProvider, validateAiSettingsDraft, type AiSettingsPayload } from './aiSettings';

const payload = {
  config: { provider: 'deepseek', format: 'openai', base_url: 'https://api.deepseek.com/v1', model: 'deepseek-chat', has_key: true },
  providers: {
    deepseek: { label: 'DeepSeek', format: 'openai', base_url: 'https://api.deepseek.com/v1', model: 'deepseek-chat' },
    claude: { label: 'Anthropic Claude', format: 'anthropic', base_url: 'https://api.anthropic.com', model: 'claude-sonnet-4-5' },
  },
  groups: { '国内': ['deepseek'], '国际': ['claude'] },
} satisfies AiSettingsPayload;

describe('AI settings form', () => {
  it('restores account-wide settings without exposing the saved API key', () => {
    expect(createAiSettingsDraft(payload)).toEqual({
      provider: 'deepseek', format: 'openai', baseUrl: 'https://api.deepseek.com/v1', model: 'deepseek-chat', apiKey: '', hasKey: true,
    });
  });

  it('distinguishes a server deployment gap from a network failure', () => {
    expect(describeAiSettingsFailure(404, 'The route api/v1/ai/settings could not be found.')).toEqual({
      title: '手机端 AI 设置接口尚未发布',
      body: '不是 AI 服务没有启动，也不是必须由管理员在服务器上手工设置。当前线上后端版本缺少手机端读取和保存设置的接口；服务器更新后，你就可以直接在这里设置，并与电脑端共用。',
      deploymentPending: true,
    });
    expect(describeAiSettingsFailure(undefined, 'Network request failed').deploymentPending).toBe(false);
  });

  it('applies a provider preset while preserving the saved-key state', () => {
    const draft = createAiSettingsDraft(payload);
    expect(selectAiProvider(draft, 'claude', payload.providers)).toMatchObject({
      provider: 'claude', format: 'anthropic', baseUrl: 'https://api.anthropic.com', model: 'claude-sonnet-4-5', hasKey: true,
    });
  });

  it('requires an endpoint and model but allows an empty key when one is already saved', () => {
    expect(validateAiSettingsDraft(createAiSettingsDraft(payload))).toBeUndefined();
    expect(validateAiSettingsDraft({ ...createAiSettingsDraft(payload), baseUrl: '' })).toBe('请填写 Base URL');
    expect(validateAiSettingsDraft({ ...createAiSettingsDraft(payload), model: '' })).toBe('请填写模型名称');
  });
});
