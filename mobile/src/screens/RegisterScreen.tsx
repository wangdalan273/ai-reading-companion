import { useState } from 'react';
import { View, StyleSheet } from 'react-native';
import { TextInput, Button, Text, ActivityIndicator } from 'react-native-paper';
import { useAuthStore } from '../state/authStore';

export function RegisterScreen({ navigation }: { navigation: any }) {
  const register = useAuthStore((s) => s.register);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit() {
    setLoading(true);
    setError(null);
    try {
      await register(name, email, password);
    } catch (e: any) {
      setError(e?.message ?? '注册失败');
    } finally {
      setLoading(false);
    }
  }

  return (
    <View style={styles.container}>
      <Text variant="headlineMedium" style={styles.title}>
        创建账号
      </Text>
      <TextInput label="昵称" value={name} onChangeText={setName} style={styles.input} />
      <TextInput
        label="邮箱"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        keyboardType="email-address"
        style={styles.input}
      />
      <TextInput
        label="密码（至少 8 位）"
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
          注册并登录
        </Button>
      )}
      <Button mode="text" onPress={() => navigation.goBack()} style={styles.input}>
        返回登录
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
