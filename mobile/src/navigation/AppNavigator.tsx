import { useEffect } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { LoginScreen } from '../screens/LoginScreen';
import { LibraryScreen } from '../screens/LibraryScreen';
import { ReaderScreen } from '../screens/ReaderScreen';
import { CompanionScreen } from '../screens/CompanionScreen';
import { StudyScreen } from '../screens/StudyScreen';
import { ProfileScreen } from '../screens/ProfileScreen';
import { BookToolsScreen } from '../screens/BookToolsScreen';
import { AiSettingsScreen } from '../screens/AiSettingsScreen';
import { useAuthStore } from '../state/authStore';
import type { Book } from '../types';
import { colors } from '../theme';

export type RootStackParamList = {
  Login: undefined;
  Main: undefined;
  Reader: { book: Book };
  BookTools: { book: Book };
  AiSettings: undefined;
};

export type MainTabParamList = { Library: undefined; Companion: undefined; Study: undefined; Profile: undefined };

const Stack = createNativeStackNavigator<RootStackParamList>();
const Tab = createBottomTabNavigator<MainTabParamList>();

function MainTabs() {
  const labels: Record<keyof MainTabParamList, string> = { Library: '书房', Companion: '伴读', Study: '复习', Profile: '我的' };
  const marks: Record<keyof MainTabParamList, string> = { Library: '书', Companion: '问', Study: '习', Profile: '我' };
  return <Tab.Navigator screenOptions={({ route }) => ({ headerShown: false, tabBarActiveTintColor: colors.accent, tabBarInactiveTintColor: colors.muted, tabBarStyle: { backgroundColor: colors.white, borderTopColor: colors.line, height: 64, paddingTop: 5, paddingBottom: 8 }, tabBarLabel: labels[route.name], tabBarIcon: ({ color }) => <View style={{ width: 25, height: 25, borderRadius: 13, borderWidth: 1, borderColor: color, alignItems: 'center', justifyContent: 'center' }}><ActivityIndicator animating={false} style={{ display: 'none' }} /><View><Text style={{ color, fontSize: 11, fontWeight: '800' }}>{marks[route.name]}</Text></View></View> })}>
    <Tab.Screen name="Library" component={LibraryScreen} /><Tab.Screen name="Companion" component={CompanionScreen} /><Tab.Screen name="Study" component={StudyScreen} /><Tab.Screen name="Profile" component={ProfileScreen} />
  </Tab.Navigator>;
}

export function AppNavigator() {
  const { user, booting, restore } = useAuthStore();
  useEffect(() => { void restore(); }, [restore]);
  if (booting) return <View style={{ flex: 1, backgroundColor: colors.paper, justifyContent: 'center' }}><ActivityIndicator color={colors.accent} /></View>;
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      {user ? (
        <>
          <Stack.Screen name="Main" component={MainTabs} />
          <Stack.Screen name="Reader" component={ReaderScreen} />
          <Stack.Screen name="BookTools" component={BookToolsScreen} />
          <Stack.Screen name="AiSettings" component={AiSettingsScreen} />
        </>
      ) : <Stack.Screen name="Login" component={LoginScreen} />}
    </Stack.Navigator>
  );
}
