import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';
import { SafeAreaView } from 'react-native-safe-area-context';
import type { RootStackParamList } from '../navigation/AppNavigator';
import { createProfilePresentation } from '../profile/profilePresentation';
import { useAuthStore } from '../state/authStore';
import { colors, typography } from '../theme';
import { api } from '../api/client';

export function ProfileScreen() {
  const navigation = useNavigation<NativeStackNavigationProp<RootStackParamList>>();
  const user = useAuthStore((state) => state.user);
  const logout = useAuthStore((state) => state.logout);
  const profile = createProfilePresentation(user);
  const queryClient = useQueryClient();
  const [syncing, setSyncing] = useState(false);
  const [syncNotice, setSyncNotice] = useState('');
  const syncNow = async () => {
    if (syncing) return;
    setSyncing(true); setSyncNotice('');
    try {
      await api.sync();
      await queryClient.invalidateQueries();
      setSyncNotice(`同步完成 · ${new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' })}`);
    } catch (error) {
      setSyncNotice(error instanceof Error ? error.message : '同步失败，请稍后重试');
    } finally { setSyncing(false); }
  };

  return <SafeAreaView style={styles.safe} edges={['top']}>
    <ScrollView contentContainerStyle={styles.page}>
      <Text style={styles.eyebrow}>ACCOUNT · SETTINGS</Text>
      <Text style={styles.title}>我的</Text>
      <View style={styles.identity}>
        <View style={styles.avatar}><Text style={styles.avatarText}>{profile.avatar}</Text></View>
        <View style={styles.identityText}><Text style={styles.name}>{profile.name}</Text><Text style={styles.email}>{profile.email}</Text></View>
      </View>

      <Text style={styles.sectionTitle}>服务设置</Text>
      <Pressable onPress={() => navigation.navigate('AiSettings')} style={({ pressed }) => [styles.actionCard, pressed && styles.pressed]}>
        <View style={styles.actionMark}><Text style={styles.actionMarkText}>AI</Text></View>
        <View style={styles.actionText}><Text style={styles.actionTitle}>{profile.aiSettings.title}</Text><Text style={styles.actionBody}>选择模型服务商、密钥和模型；设置后手机与电脑共同使用</Text></View>
        <View style={styles.actionEnd}><Text style={styles.actionLink}>{profile.aiSettings.action}</Text><Text style={styles.chevron}>›</Text></View>
      </Pressable>

      <Text style={styles.sectionTitle}>数据状态</Text>
      <View style={styles.statusCard}>{profile.dataStatus.map((item, index) => <View key={item.title} style={[styles.statusRow, index > 0 && styles.statusBorder]}><View style={styles.statusText}><Text style={styles.statusTitle}>{item.title}</Text><Text style={styles.statusBody}>{item.detail}</Text></View><Text style={[styles.badge, index > 0 && styles.localBadge]}>{item.badge}</Text></View>)}</View>
      <Pressable disabled={syncing} onPress={() => void syncNow()} style={styles.syncButton}>{syncing ? <ActivityIndicator color={colors.white} /> : <Text style={styles.syncButtonText}>立即与电脑端同步</Text>}</Pressable>
      {!!syncNotice && <Text style={styles.statusNote}>{syncNotice}</Text>}

      <View style={styles.versionRow}><Text style={styles.versionTitle}>阅伴移动端</Text><Text style={styles.versionText}>V2 · EPUB / PDF</Text></View>
      <Pressable onPress={() => void logout()} style={styles.logout}><Text style={styles.logoutText}>退出登录</Text></Pressable>
    </ScrollView>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.paper }, page: { padding: 22, paddingBottom: 44 },
  eyebrow: { color: colors.accent, fontSize: 10, letterSpacing: 2, fontWeight: '800' }, title: { color: colors.ink, fontFamily: typography.display, fontSize: 31, marginTop: 5 },
  identity: { flexDirection: 'row', alignItems: 'center', gap: 16, marginTop: 24, marginBottom: 8 }, avatar: { width: 62, height: 62, borderRadius: 31, backgroundColor: colors.ink, alignItems: 'center', justifyContent: 'center' }, avatarText: { color: colors.paper, fontFamily: typography.display, fontSize: 26 }, identityText: { flex: 1 }, name: { color: colors.ink, fontFamily: typography.display, fontSize: 22 }, email: { color: colors.muted, marginTop: 4, fontSize: 12 },
  sectionTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 20, marginTop: 26, marginBottom: 10 }, actionCard: { minHeight: 96, flexDirection: 'row', alignItems: 'center', gap: 13, backgroundColor: colors.ink, borderRadius: 20, padding: 16 }, pressed: { opacity: .82 }, actionMark: { width: 43, height: 43, borderRadius: 13, backgroundColor: colors.accent, alignItems: 'center', justifyContent: 'center' }, actionMarkText: { color: colors.white, fontWeight: '900', fontSize: 13 }, actionText: { flex: 1 }, actionTitle: { color: colors.paper, fontFamily: typography.display, fontSize: 19 }, actionBody: { color: '#CBBFB0', fontSize: 11, lineHeight: 17, marginTop: 4 }, actionEnd: { alignItems: 'center' }, actionLink: { color: '#E2B8A5', fontSize: 10, fontWeight: '800' }, chevron: { color: colors.paper, fontSize: 25, lineHeight: 25 },
  statusCard: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 18, paddingHorizontal: 16 }, statusRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 16 }, statusBorder: { borderTopWidth: 1, borderTopColor: colors.line }, statusText: { flex: 1 }, statusTitle: { color: colors.ink, fontWeight: '800' }, statusBody: { color: colors.muted, fontSize: 12, lineHeight: 19, marginTop: 4 }, badge: { color: colors.accent, backgroundColor: colors.accentSoft, overflow: 'hidden', borderRadius: 10, paddingHorizontal: 8, paddingVertical: 4, fontSize: 9, fontWeight: '800' }, localBadge: { color: colors.accent, backgroundColor: colors.accentSoft }, syncButton: { marginTop: 12, minHeight: 48, borderRadius: 15, backgroundColor: colors.accent, alignItems: 'center', justifyContent: 'center' }, syncButtonText: { color: colors.white, fontWeight: '900' }, statusNote: { color: colors.muted, fontSize: 11, lineHeight: 18, marginTop: 9, textAlign: 'center' },
  versionRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 25, paddingVertical: 14, borderTopWidth: 1, borderBottomWidth: 1, borderColor: colors.line }, versionTitle: { color: colors.ink, fontWeight: '700' }, versionText: { color: colors.muted, fontSize: 12 }, logout: { borderWidth: 1, borderColor: colors.danger, borderRadius: 16, padding: 15, alignItems: 'center', marginTop: 20 }, logoutText: { color: colors.danger, fontWeight: '800' },
});
