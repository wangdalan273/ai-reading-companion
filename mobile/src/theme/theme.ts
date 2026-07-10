import { DefaultTheme, DarkTheme } from 'react-native-paper';

/**
 * AI 伴读品牌调性 —— 温润、书卷气、学术感。
 *
 * 主色系取自电脑端阅读器（resources/js/reader.js）：
 *   深咖底 #1c1917 · 羊皮纸 #FBF4E6 · 金 #D4933A / #E2A83A
 * 整个移动端 App 的 Material 配色统一为暖金调，
 * 装上去后与 Web 端的阅读体验视觉一致。
 */
export const lightTheme = {
  ...DefaultTheme,
  colors: {
    ...DefaultTheme.colors,
    primary: '#B8761B',          // 暖金（主按钮/强调）
    onPrimary: '#FFFFFF',
    primaryContainer: '#F5E6CC', // 浅奶油（选中态背景）
    background: '#FBF4E6',       // 羊皮纸白（主背景 = 阅读器浅色页）
    surface: '#FFF9ED',
    surfaceVariant: '#EDE0CA',
    outline: '#8C7D65',
    error: '#B3261E',
  },
};

export const darkTheme = {
  ...DarkTheme,
  colors: {
    ...DarkTheme.colors,
    primary: '#E2A83C',           // 亮金（暗色模式强调）
    background: '#1C1917',        // 深咖（= 阅读器 dark 底色）
    surface: '#292524',
  },
};

// 默认跟随系统；用户可在设置里手动切换
export const theme = lightTheme;
