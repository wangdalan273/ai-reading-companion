import { useEffect, useState } from 'react';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api, ApiError } from '../api/client';
import type { RootStackParamList } from '../navigation/AppNavigator';
import { createAiSettingsDraft, describeAiSettingsFailure, selectAiProvider, validateAiSettingsDraft, type AiSettingsDraft } from '../settings/aiSettings';
import { colors, typography } from '../theme';

type Props = NativeStackScreenProps<RootStackParamList, 'AiSettings'>;

export function AiSettingsScreen({ navigation }: Props) {
  const queryClient = useQueryClient();
  const settings = useQuery({ queryKey: ['ai', 'settings'], queryFn: api.aiSettings });
  const [draft, setDraft] = useState<AiSettingsDraft>();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [notice, setNotice] = useState<{ ok: boolean; text: string }>();

  useEffect(() => { if (settings.data) setDraft(createAiSettingsDraft(settings.data)); }, [settings.data]);

  const save = useMutation({
    mutationFn: api.saveAiSettings,
    onSuccess: (payload) => {
      queryClient.setQueryData(['ai', 'settings'], payload);
      setDraft(createAiSettingsDraft(payload));
      setNotice({ ok: true, text: '已保存。手机端与电脑端现在使用同一套 AI 设置。' });
    },
    onError: (error) => setNotice({ ok: false, text: error instanceof Error ? error.message : '保存失败' }),
  });
  const testConnection = useMutation({
    mutationFn: async (settingsDraft: AiSettingsDraft) => {
      const payload = await api.saveAiSettings(settingsDraft);
      queryClient.setQueryData(['ai', 'settings'], payload);
      setDraft(createAiSettingsDraft(payload));
      return api.testAiSettings();
    },
    onSuccess: (result) => setNotice({ ok: result.ok, text: `设置已保存。${result.message}` }),
    onError: (error) => setNotice({ ok: false, text: error instanceof Error ? error.message : '连接测试失败' }),
  });

  const submit = () => {
    if (!draft) return;
    const error = validateAiSettingsDraft(draft);
    if (error) return setNotice({ ok: false, text: error });
    save.mutate(draft);
  };

  const saveAndTest = () => {
    if (!draft) return;
    const error = validateAiSettingsDraft(draft);
    if (error) return setNotice({ ok: false, text: error });
    testConnection.mutate(draft);
  };

  if (settings.isLoading || (!settings.isError && (!settings.data || !draft))) return <SafeAreaView style={styles.safe}><View style={styles.center}><ActivityIndicator color={colors.accent} /><Text style={styles.loading}>正在读取账号 AI 设置…</Text></View></SafeAreaView>;
  if (settings.isError || !settings.data || !draft) {
    const message = settings.error instanceof Error ? settings.error.message : '请检查网络后重试';
    const failure = describeAiSettingsFailure(settings.error instanceof ApiError ? settings.error.status : undefined, message);
    return <SafeAreaView style={styles.safe}><View style={styles.center}><Text style={styles.errorKicker}>{failure.deploymentPending ? 'MOBILE SETTINGS API PENDING' : 'CONNECTION INTERRUPTED'}</Text><Text style={styles.errorTitle}>{failure.title}</Text><Text style={styles.errorBody}>{failure.body}</Text>{!failure.deploymentPending && <Pressable onPress={() => void settings.refetch()} style={styles.primaryButton}><Text style={styles.primaryText}>重新加载</Text></Pressable>}<Pressable onPress={() => navigation.goBack()} style={styles.backToProfile}><Text style={styles.backToProfileText}>返回我的页面</Text></Pressable></View></SafeAreaView>;
  }

  const payload = settings.data;
  const provider = payload.providers[draft.provider];
  return <SafeAreaView style={styles.safe} edges={['top']}>
    <View style={styles.toolbar}><Pressable onPress={() => navigation.goBack()} style={styles.backButton}><Text style={styles.back}>‹</Text></Pressable><View><Text style={styles.toolbarTitle}>AI 服务设置</Text><Text style={styles.toolbarSub}>账号级配置 · 手机与电脑共用</Text></View></View>
    <ScrollView contentContainerStyle={styles.page} keyboardShouldPersistTaps="handled">
      <View style={styles.security}><Text style={styles.securityKicker}>密钥安全</Text><Text style={styles.securityText}>API Key 加密保存在服务器，手机和电脑都不会读取已保存的明文密钥。</Text></View>

      <Text style={styles.label}>模型服务商</Text>
      <Pressable onPress={() => setPickerOpen(true)} style={styles.providerButton}><View><Text style={styles.providerName}>{provider?.label || draft.provider}</Text><Text style={styles.providerFormat}>{draft.format.toUpperCase()} 协议</Text></View><Text style={styles.providerChange}>更换 ›</Text></Pressable>

      <Text style={styles.label}>API Key</Text>
      <TextInput secureTextEntry value={draft.apiKey} onChangeText={(apiKey) => setDraft({ ...draft, apiKey })} placeholder={draft.hasKey ? '已配置；留空可继续使用原密钥' : '请输入服务商提供的密钥'} placeholderTextColor={colors.muted} autoCapitalize="none" style={styles.input} />
      <Text style={styles.fieldHint}>{draft.hasKey ? '账号中已有加密密钥。只有输入新值时才会替换。' : '尚未配置密钥；不填写时 AI 将使用离线演示模式。'}</Text>

      <Text style={styles.label}>Base URL</Text>
      <TextInput value={draft.baseUrl} onChangeText={(baseUrl) => setDraft({ ...draft, baseUrl })} autoCapitalize="none" autoCorrect={false} style={styles.input} />
      <Text style={styles.label}>模型名称</Text>
      <TextInput value={draft.model} onChangeText={(model) => setDraft({ ...draft, model })} autoCapitalize="none" autoCorrect={false} style={styles.input} />

      {notice && <View style={[styles.notice, !notice.ok && styles.noticeError]}><Text style={styles.noticeText}>{notice.text}</Text></View>}
      <Pressable disabled={save.isPending} onPress={submit} style={styles.primaryButton}><Text style={styles.primaryText}>{save.isPending ? '保存中…' : '保存账号设置'}</Text></Pressable>
      <Pressable disabled={testConnection.isPending || save.isPending} onPress={saveAndTest} style={styles.secondaryButton}><Text style={styles.secondaryText}>{testConnection.isPending ? '保存并测试中…' : '保存并测试连接'}</Text></Pressable>
      <Text style={styles.bottomHint}>本地 Ollama / LM Studio 只有在服务器能够访问对应地址时才可用；手机上的 localhost 指向手机本身。</Text>
    </ScrollView>

    <Modal visible={pickerOpen} transparent animationType="slide" onRequestClose={() => setPickerOpen(false)}>
      <View style={styles.modalShade}><SafeAreaView style={styles.sheet} edges={['bottom']}><View style={styles.sheetHead}><View><Text style={styles.sheetKicker}>AI PROVIDER</Text><Text style={styles.sheetTitle}>选择服务商</Text></View><Pressable onPress={() => setPickerOpen(false)}><Text style={styles.close}>关闭</Text></Pressable></View><ScrollView contentContainerStyle={styles.providerList}>{Object.entries(payload.groups).map(([group, keys]) => <View key={group}><Text style={styles.group}>{group}</Text>{keys.map((key) => { const item = payload.providers[key]; if (!item) return null; const active = key === draft.provider; return <Pressable key={key} onPress={() => { setDraft(selectAiProvider(draft, key, payload.providers)); setPickerOpen(false); setNotice(undefined); }} style={[styles.providerRow, active && styles.providerActive]}><View style={styles.providerRowText}><Text style={styles.rowName}>{item.label}</Text><Text style={styles.rowMeta}>{item.model || '自定义模型'} · {item.format}</Text></View><Text style={styles.check}>{active ? '已选' : '选择'}</Text></Pressable>; })}</View>)}</ScrollView></SafeAreaView></View>
    </Modal>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.paper }, toolbar: { minHeight: 68, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12, borderBottomWidth: 1, borderBottomColor: colors.line }, backButton: { width: 44, height: 44, justifyContent: 'center' }, back: { color: colors.ink, fontSize: 38, lineHeight: 40 }, toolbarTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 20 }, toolbarSub: { color: colors.muted, fontSize: 9, marginTop: 2 }, page: { padding: 20, paddingBottom: 44 }, center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 30 }, loading: { color: colors.muted, marginTop: 12 }, errorKicker: { color: colors.accent, fontSize: 10, fontWeight: '900', letterSpacing: 1.7, marginBottom: 10 }, errorTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 24, textAlign: 'center' }, errorBody: { color: colors.muted, marginTop: 10, textAlign: 'center', lineHeight: 22 }, backToProfile: { padding: 14, marginTop: 8 }, backToProfileText: { color: colors.ink, fontWeight: '800' }, security: { backgroundColor: colors.ink, borderRadius: 18, padding: 17 }, securityKicker: { color: '#E1B59F', fontSize: 10, fontWeight: '900', letterSpacing: 1.5 }, securityText: { color: colors.paper, fontSize: 13, lineHeight: 21, marginTop: 7 }, label: { color: colors.ink, fontSize: 12, fontWeight: '800', marginTop: 21, marginBottom: 7 }, providerButton: { minHeight: 67, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 15, paddingHorizontal: 15 }, providerName: { color: colors.ink, fontFamily: typography.display, fontSize: 18 }, providerFormat: { color: colors.muted, fontSize: 9, marginTop: 3 }, providerChange: { color: colors.accent, fontWeight: '800', fontSize: 12 }, input: { minHeight: 51, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 14, paddingHorizontal: 14, color: colors.ink, fontSize: 14 }, fieldHint: { color: colors.muted, fontSize: 10, lineHeight: 16, marginTop: 6 }, notice: { backgroundColor: '#DFE8D9', borderRadius: 12, padding: 12, marginTop: 19 }, noticeError: { backgroundColor: '#F2DCD5' }, noticeText: { color: colors.ink, fontSize: 12, lineHeight: 18 }, primaryButton: { backgroundColor: colors.accent, borderRadius: 15, padding: 15, alignItems: 'center', marginTop: 18 }, primaryText: { color: colors.white, fontWeight: '900' }, secondaryButton: { borderWidth: 1, borderColor: colors.ink, borderRadius: 15, padding: 14, alignItems: 'center', marginTop: 10 }, secondaryText: { color: colors.ink, fontWeight: '800' }, bottomHint: { color: colors.muted, fontSize: 10, lineHeight: 17, marginTop: 13 }, modalShade: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(20,17,14,.45)' }, sheet: { height: '82%', backgroundColor: colors.paper, borderTopLeftRadius: 26, borderTopRightRadius: 26, overflow: 'hidden' }, sheetHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', padding: 20 }, sheetKicker: { color: colors.accent, fontSize: 9, fontWeight: '900', letterSpacing: 1.5 }, sheetTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 26, marginTop: 3 }, close: { color: colors.accent, fontWeight: '800' }, providerList: { paddingHorizontal: 18, paddingBottom: 35 }, group: { color: colors.muted, fontSize: 10, fontWeight: '800', letterSpacing: 1.2, marginTop: 15, marginBottom: 7 }, providerRow: { flexDirection: 'row', alignItems: 'center', minHeight: 61, borderWidth: 1, borderColor: colors.line, borderRadius: 14, backgroundColor: colors.white, paddingHorizontal: 14, marginBottom: 7 }, providerActive: { borderColor: colors.accent, backgroundColor: colors.accentSoft }, providerRowText: { flex: 1 }, rowName: { color: colors.ink, fontWeight: '800' }, rowMeta: { color: colors.muted, fontSize: 10, marginTop: 4 }, check: { color: colors.accent, fontSize: 10, fontWeight: '900' },
});
