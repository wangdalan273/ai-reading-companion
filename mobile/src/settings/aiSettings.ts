export type AiProviderPreset = {
  label: string;
  format: 'openai' | 'anthropic' | 'gemini';
  base_url: string;
  model: string;
};

export type AiSettingsPayload = {
  config: {
    provider: string;
    format: 'openai' | 'anthropic' | 'gemini';
    base_url: string;
    model: string;
    has_key: boolean;
  };
  providers: Record<string, AiProviderPreset>;
  groups: Record<string, string[]>;
};

export type AiSettingsDraft = {
  provider: string;
  format: 'openai' | 'anthropic' | 'gemini';
  baseUrl: string;
  model: string;
  apiKey: string;
  hasKey: boolean;
};

export type AiSettingsFailure = {
  title: string;
  body: string;
  deploymentPending: boolean;
};

export function describeAiSettingsFailure(status: number | undefined, message: string): AiSettingsFailure {
  if (status === 404 || /route .*ai\/settings.*not be found/i.test(message)) {
    return {
      title: '手机端 AI 设置接口尚未发布',
      body: '不是 AI 服务没有启动，也不是必须由管理员在服务器上手工设置。当前线上后端版本缺少手机端读取和保存设置的接口；服务器更新后，你就可以直接在这里设置，并与电脑端共用。',
      deploymentPending: true,
    };
  }
  return {
    title: '暂时无法读取 AI 设置',
    body: message || '请检查网络后重试。',
    deploymentPending: false,
  };
}

export function createAiSettingsDraft(payload: AiSettingsPayload): AiSettingsDraft {
  return {
    provider: payload.config.provider,
    format: payload.config.format,
    baseUrl: payload.config.base_url,
    model: payload.config.model,
    apiKey: '',
    hasKey: payload.config.has_key,
  };
}

export function selectAiProvider(draft: AiSettingsDraft, provider: string, providers: Record<string, AiProviderPreset>): AiSettingsDraft {
  const preset = providers[provider];
  if (!preset) return draft;
  return {
    ...draft,
    provider,
    format: preset.format,
    baseUrl: preset.base_url,
    model: preset.model,
  };
}

export function validateAiSettingsDraft(draft: AiSettingsDraft): string | undefined {
  if (!draft.baseUrl.trim()) return '请填写 Base URL';
  if (!draft.model.trim()) return '请填写模型名称';
  if (!draft.hasKey && !draft.apiKey.trim()) return '请填写 API Key，或在电脑端先完成配置';
  return undefined;
}
