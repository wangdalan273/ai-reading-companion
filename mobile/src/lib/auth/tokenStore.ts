import * as SecureStore from 'expo-secure-store';

/**
 * Token 安全存储：iOS 落 Keychain，Android 落 EncryptedSharedPreferences。
 * 绝不存明文、绝不存 AsyncStorage/UserDefaults。
 */
const KEY = 'auth_token';

export const tokenStore = {
  get: () => SecureStore.getItemAsync(KEY),
  set: (token: string) => SecureStore.setItemAsync(KEY, token),
  clear: () => SecureStore.deleteItemAsync(KEY),
};
