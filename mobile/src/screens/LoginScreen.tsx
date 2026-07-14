import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuthStore } from '../state/authStore';
import { colors, typography } from '../theme';

export function LoginScreen() {
  const login = useAuthStore((state) => state.login);
  const register = useAuthStore((state) => state.register);
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const submit = async () => {
    if (!email.trim() || !password || (creating && !name.trim())) return setError('请完整填写账号信息');
    setLoading(true); setError('');
    try { creating ? await register(name.trim(), email.trim(), password) : await login(email.trim(), password); }
    catch (e) { setError(e instanceof Error ? e.message : '登录失败'); }
    finally { setLoading(false); }
  };
  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView style={styles.page} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <View style={styles.mark}><Text style={styles.markText}>阅</Text></View>
        <Text style={styles.kicker}>AI READING COMPANION</Text>
        <Text style={styles.title}>把阅读，变成长期记忆</Text>
        <Text style={styles.subtitle}>你的书、标注和思考，在所有设备之间自然延续。</Text>
        <View style={styles.form}>
          {creating && <TextInput style={styles.input} placeholder="昵称" placeholderTextColor={colors.muted} value={name} onChangeText={setName} />}
          <TextInput style={styles.input} placeholder="邮箱" placeholderTextColor={colors.muted} autoCapitalize="none" keyboardType="email-address" value={email} onChangeText={setEmail} />
          <TextInput style={styles.input} placeholder="密码" placeholderTextColor={colors.muted} secureTextEntry value={password} onChangeText={setPassword} />
          {!!error && <Text style={styles.error}>{error}</Text>}
          <Pressable style={[styles.button, loading && styles.disabled]} onPress={submit} disabled={loading}>
            <Text style={styles.buttonText}>{loading ? '正在处理…' : creating ? '创建账号' : '进入书房'}</Text>
          </Pressable>
          <Pressable onPress={() => { setCreating((value) => !value); setError(''); }}><Text style={styles.switch}>{creating ? '已有账号，直接登录' : '第一次使用？创建账号'}</Text></Pressable>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.paper }, page: { flex: 1, padding: 28, justifyContent: 'center' },
  mark: { width: 62, height: 62, borderRadius: 31, backgroundColor: colors.ink, alignItems: 'center', justifyContent: 'center', marginBottom: 30 },
  markText: { color: colors.paper, fontFamily: typography.display, fontSize: 29 }, kicker: { color: colors.accent, letterSpacing: 2.4, fontSize: 11, fontWeight: '700' },
  title: { color: colors.ink, fontFamily: typography.display, fontSize: 35, lineHeight: 48, marginTop: 14 }, subtitle: { color: colors.muted, fontSize: 15, lineHeight: 24, marginTop: 12, maxWidth: 330 },
  form: { marginTop: 42, gap: 13 }, input: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 14, paddingHorizontal: 17, height: 54, color: colors.ink, fontSize: 16 },
  error: { color: colors.danger, fontSize: 14 }, button: { marginTop: 6, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.accent }, disabled: { opacity: .55 }, buttonText: { color: colors.white, fontWeight: '700', fontSize: 16 },
  switch: { color: colors.accent, textAlign: 'center', paddingVertical: 10, fontWeight: '600' },
});
