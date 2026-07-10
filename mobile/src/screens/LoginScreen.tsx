import { useState } from 'react';
import { View, StyleSheet } from 'react-native';
import { TextInput, Button, Text, ActivityIndicator } from 'react-native-paper';
import { useAuthStore } from '../state/authStore';

export function LoginScreen({ navigation }: { navigation: any }) {
  const login = useAuthStore((s) => s.login);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit() {
    setLoading(true);
    setError(null);
    try {
      await login(email, password);
      // user 注入后 AppNavigator 自动切到主应用
    } catch (e: any) {
      setError(e?.message ?? '登录失败');
    } finally {
      setLoading(false);
    }
  }

  return (
    <View style={styles.container}>
      <Text variant="headlineMedium" style={styles.title}>
        AI 伴读
      </Text>
      <TextInput
        label="邮箱"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        keyboardType="email-address"
        style={styles.input}
      />
      <TextInput
        label="密码"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        style={styles.input}
      />
      {error && <Text style={styles.error}>{error}</Text>}
      {loading ? (
        <ActivityIndicator style={styles.input} />
      ) : (
        <Button mode="contained" onPress={onSubmit} style={styles.input}>
          登录
        </Button>
      )}
      <Button
        mode="text"
        onPress={() => navigation.navigate('Register')}
        style={styles.input}
      >
        没有账号？注册
      </Button>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    padding: 24,
    backgroundColor: '#F6F5F8',
  },
  title: { textAlign: 'center', marginBottom: 24 },
  input: { marginBottom: 12 },
  error: { color: '#B3261E', marginBottom: 12 },
});
