import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { Ionicons } from '@expo/vector-icons';
import { BookListScreen } from '../screens/BookListScreen';
import { FlashcardScreen } from '../screens/FlashcardScreen';
import { StatsScreen } from '../screens/StatsScreen';
import { CompanionScreen } from '../screens/CompanionScreen';

const Tab = createBottomTabNavigator();

/**
 * 底部 Tab：书籍 / 闪卡 / 统计 / 伴读。
 * Android 优先：用 Material 风格图标与配色（已在 theme 设 primary）。
 */
export function MainTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: true,
        headerTitleAlign: 'center',
      }}
    >
      <Tab.Screen
        name="Books"
        component={BookListScreen}
        options={{
          title: '书籍库',
          tabBarIcon: ({ color, size }) => (
            <Ionicons name="library-outline" color={color} size={size} />
          ),
        }}
      />
      <Tab.Screen
        name="Flashcards"
        component={FlashcardScreen}
        options={{
          title: '闪卡',
          tabBarIcon: ({ color, size }) => (
            <Ionicons name="albums-outline" color={color} size={size} />
          ),
        }}
      />
      <Tab.Screen
        name="Stats"
        component={StatsScreen}
        options={{
          title: '统计',
          tabBarIcon: ({ color, size }) => (
            <Ionicons name="bar-chart-outline" color={color} size={size} />
          ),
        }}
      />
      <Tab.Screen
        name="Companion"
        component={CompanionScreen}
        options={{
          title: '伴读',
          tabBarIcon: ({ color, size }) => (
            <Ionicons name="chatbubble-ellipses-outline" color={color} size={size} />
          ),
        }}
      />
    </Tab.Navigator>
  );
}
