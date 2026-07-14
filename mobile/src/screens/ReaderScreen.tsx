import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Pdf, { type PdfRef, type TableContent } from 'react-native-pdf';
import { Reader, useReader, type Section, type Toc } from '@epubjs-react-native/core';
import * as Clipboard from 'expo-clipboard';
import type { RootStackParamList } from '../navigation/AppNavigator';
import { colors, typography } from '../theme';
import { BookOpenError, downloadAndValidateBook } from '../reader/downloadBook';
import { initialReaderState, readerReducer } from '../reader/readerMachine';
import { api } from '../api/client';
import { getRenderTimeoutMs } from '../reader/reliability';
import { buildConversationContext, formatThreadForCollection } from '../companion/conversation';
import { useEpubFileSystem } from '../reader/useEpubFileSystem';
import {
  buildEpubNavigationScript,
  buildSelectionModeScript,
  EPUB_READER_SELECTION_ENABLED,
  shouldAcceptSelection,
} from '../reader/readerInteraction';
import {
  createReadingSession,
  readingSessionStore,
  selectionReducer,
  toggleBookmark,
  updateReadingProgress,
  type ReadingSession,
} from '../reader/readingSession';

type Props = NativeStackScreenProps<RootStackParamList, 'Reader'>;
type NavItem = { id: string; label: string; target?: string; page?: number; depth: number };

const READER_AI_STARTER = '请结合原文解释这段话：它在本章表达了什么、关键概念是什么、我应该如何理解？';

function flattenToc(items: Toc | Section[], depth = 0): NavItem[] {
  return items.flatMap((item) => [
    { id: item.id || item.href, label: item.label || '未命名章节', target: item.href, depth },
    ...flattenToc((item.subitems ?? []) as Section[], depth + 1),
  ]);
}

function flattenPdfToc(items: TableContent[] = [], depth = 0): NavItem[] {
  return items.flatMap((item, index) => [
    { id: `${item.pageIdx}-${index}-${item.title}`, label: item.title || `第 ${item.pageIdx + 1} 页`, page: item.pageIdx + 1, depth },
    ...flattenPdfToc(item.children ?? [], depth + 1),
  ]);
}

export function ReaderScreen({ route, navigation }: Props) {
  const { book } = route.params;
  const epub = useReader();
  const pdfRef = useRef<PdfRef>(null);
  const [state, dispatch] = useReducer(readerReducer, initialReaderState);
  const [selection, selectionDispatch] = useReducer(selectionReducer, { mode: 'idle' });
  const [session, setSession] = useState<ReadingSession>(() => createReadingSession(book.id, book.format));
  const sessionRef = useRef(session);
  const saveTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const [sessionReady, setSessionReady] = useState(false);
  const [rendererReady, setRendererReady] = useState(false);
  const [toc, setToc] = useState<NavItem[]>([]);
  const [drawer, setDrawer] = useState<'toc' | 'bookmarks'>();
  const [selectionMode, setSelectionMode] = useState(false);
  const [copied, setCopied] = useState(false);
  const [navigationMessage, setNavigationMessage] = useState<{ kind: 'loading' | 'error' | 'success'; text: string }>();
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [asking, setAsking] = useState(false);
  const [aiQuestion, setAiQuestion] = useState('');
  const [savingAnswerId, setSavingAnswerId] = useState<string>();
  const attempt = useRef(0);
  const openedAt = useRef(Date.now());

  const persist = useCallback((next: ReadingSession, immediate = false) => {
    sessionRef.current = next;
    setSession(next);
    if (saveTimer.current) clearTimeout(saveTimer.current);
    if (immediate) void readingSessionStore.save(next);
    else saveTimer.current = setTimeout(() => void readingSessionStore.save(sessionRef.current), 700);
  }, []);

  useEffect(() => {
    let active = true;
    void readingSessionStore.load(book.id, book.format).then((stored) => {
      if (!active) return;
      sessionRef.current = stored;
      setSession(stored);
      setSessionReady(true);
    });
    return () => { active = false; };
  }, [book.format, book.id]);

  const open = useCallback(async () => {
    const id = ++attempt.current;
    setRendererReady(false);
    dispatch({ type: 'OPEN', bookId: book.id, format: book.format });
    try {
      const result = await downloadAndValidateBook(book.id, book.format, (received, total) => {
        if (id === attempt.current) dispatch({ type: 'DOWNLOAD_PROGRESS', received, total });
      }, { expectedBytes: book.size });
      if (id !== attempt.current) return;
      dispatch({ type: 'DOWNLOADED', uri: result.uri, bytes: result.bytes });
      dispatch({ type: 'VALIDATED' });
    } catch (error) {
      if (id !== attempt.current) return;
      const known = error instanceof BookOpenError ? error : new BookOpenError('network', '无法下载书籍');
      dispatch({ type: 'FAILED', code: known.code, message: known.message });
    }
  }, [book]);

  useEffect(() => {
    void open();
    return () => {
      attempt.current += 1;
      if (saveTimer.current) clearTimeout(saveTimer.current);
      void readingSessionStore.save(sessionRef.current);
      const seconds = Math.min(3600, Math.floor((Date.now() - openedAt.current) / 1000));
      if (seconds > 0) void api.logReading(book.id, seconds);
    };
  }, [book.id, open]);

  const failRender = (message: string) => dispatch({ type: 'FAILED', code: 'render', message });
  useEffect(() => {
    if (state.status !== 'ready' || rendererReady) return;
    const timeout = setTimeout(() => failRender(book.format === 'epub'
      ? 'EPUB 排版长时间没有响应。文件已下载成功，请重试；若仍失败，可能是书籍内部结构不兼容。'
      : 'PDF 渲染长时间没有响应，请重试或重新导入文件。'), getRenderTimeoutMs(book.format, state.bytes));
    return () => clearTimeout(timeout);
  }, [book.format, rendererReady, state.bytes, state.status]);

  const saveNote = async () => {
    if (selection.mode !== 'note') return;
    setSaving(true);
    try {
      await api.addAnnotation(book.id, { loc: selection.locator, quote: selection.quote, note: note.trim() || undefined });
      setNote('');
      closeSelection();
    } finally { setSaving(false); }
  };

  const askAi = async (question = READER_AI_STARTER) => {
    if (selection.mode !== 'ai' || asking || !question.trim()) return;
    const previousMessages = selection.messages;
    const userMessageId = `reader-user-${Date.now()}`;
    const answerMessageId = `reader-ai-${Date.now()}`;
    selectionDispatch({ type: 'AI_ASKED', id: userMessageId, question: question.trim() });
    setAsking(true);
    try {
      const answer = await api.askCompanion(question.trim(), undefined, 'book', {
        bookId: book.id,
        context: buildConversationContext(selection.quote, previousMessages),
      });
      selectionDispatch({ type: 'AI_ANSWERED', id: answerMessageId, answer: answer || 'AI 暂时没有返回内容，请稍后重试。', failed: !answer });
    } catch (error) {
      selectionDispatch({ type: 'AI_ANSWERED', id: answerMessageId, answer: error instanceof Error ? error.message : 'AI 暂时不可用。', failed: true });
    } finally { setAsking(false); }
  };

  useEffect(() => { if (selection.mode === 'ai' && selection.messages.length === 0) void askAi(); }, [selection.mode]);

  const saveAnswer = async (messageId: string) => {
    if (selection.mode !== 'ai') return;
    const answer = selection.messages.find((message) => message.id === messageId && message.role === 'assistant');
    if (!answer || answer.saved || answer.failed) return;
    setSavingAnswerId(messageId);
    try {
      await api.addToKnowledgeBase({
        content: `原文：${selection.quote}\n\n${formatThreadForCollection(selection.messages, messageId)}`,
        title: `《${book.title}》划线解读`,
        bookId: book.id,
      });
      selectionDispatch({ type: 'ANSWER_SAVED', id: messageId });
    } finally { setSavingAnswerId(undefined); }
  };

  const sendFollowUp = () => {
    const question = aiQuestion.trim();
    if (!question) return;
    setAiQuestion('');
    void askAi(question);
  };

  const toggleCurrentBookmark = () => {
    if (book.format === 'epub' && !sessionRef.current.locator) return;
    const next = toggleBookmark(sessionRef.current, book.format === 'epub' ? {
      locator: sessionRef.current.locator,
      label: sessionRef.current.sectionTitle || `进度 ${Math.round(sessionRef.current.progress * 100)}%`,
    } : {
      page: sessionRef.current.page || 1,
      label: `第 ${sessionRef.current.page || 1} 页`,
    });
    persist(next, true);
  };

  const currentBookmarked = session.bookmarks.some((item) => book.format === 'epub'
    ? !!session.locator && item.locator === session.locator
    : item.page === (session.page || 1));

  const setTextSelection = useCallback((enabled: boolean) => {
    setSelectionMode(enabled);
    setCopied(false);
    if (book.format === 'epub') epub.injectJavascript(buildSelectionModeScript(enabled));
    if (!enabled) epub.removeSelection();
  }, [book.format, epub]);

  const closeSelection = () => {
    selectionDispatch({ type: 'CLOSE' });
    setTextSelection(false);
  };

  const goTo = (item: NavItem) => {
    if (item.target) {
      setNavigationMessage({ kind: 'loading', text: `正在跳转到“${item.label}”…` });
      epub.injectJavascript(buildEpubNavigationScript(item.target));
    }
    if (item.page) {
      pdfRef.current?.setPage(item.page);
      setDrawer(undefined);
      setNavigationMessage({ kind: 'success', text: `已跳转到第 ${item.page} 页` });
    }
  };

  const copySelection = async () => {
    if (selection.mode === 'idle') return;
    await Clipboard.setStringAsync(selection.quote);
    setCopied(true);
  };

  const bookmarks: NavItem[] = session.bookmarks.map((item) => ({
    id: item.id, label: item.label, target: item.locator, page: item.page, depth: 0,
  }));

  return <SafeAreaView style={styles.safe} edges={['top']}>
    <View style={styles.toolbar}>
      <Pressable onPress={() => navigation.goBack()} style={styles.iconButton}><Text style={styles.backText}>‹</Text></Pressable>
      <View style={styles.titleWrap}><Text style={styles.title} numberOfLines={1}>{book.title}</Text><Text style={styles.sub}>{session.sectionTitle || `${Math.round(session.progress * 100)}% · ${book.format.toUpperCase()}`}</Text></View>
      {book.format === 'epub' && <Pressable disabled={!rendererReady} onPress={() => setTextSelection(!selectionMode)} style={[styles.iconButton, selectionMode && styles.modeActive]}><Text style={[styles.iconText, selectionMode && styles.modeActiveText]}>{selectionMode ? '完成' : '选文'}</Text></Pressable>}
      <Pressable onPress={() => setDrawer('toc')} style={styles.iconButton}><Text style={styles.iconText}>目录</Text></Pressable>
      <Pressable onPress={() => setDrawer('bookmarks')} disabled={!sessionReady} style={[styles.iconButton, currentBookmarked && styles.bookmarkIndicator]}><Text style={styles.iconText}>书签{session.bookmarks.length ? ` ${session.bookmarks.length}` : ''}</Text></Pressable>
    </View>
    {selectionMode && <View style={styles.selectionModeBanner}><Text style={styles.selectionModeText}>选文模式：长按文字并拖动选取；完成后可标注或问 AI</Text></View>}
    {(state.status === 'downloading' || state.status === 'validating' || !sessionReady) && <View style={styles.center}><ActivityIndicator size="large" color={colors.accent} /><Text style={styles.stage}>{!sessionReady ? '正在恢复上次阅读位置…' : state.status === 'validating' ? '正在校验书籍…' : '正在下载书籍…'}</Text><Text style={styles.progress}>{state.progress > 0 ? `${Math.round(state.progress * 100)}%` : '通常只需要几秒'}</Text></View>}
    {state.status === 'error' && <View style={styles.center}><Text style={styles.errorCode}>OPEN / {state.error?.code.toUpperCase()}</Text><Text style={styles.errorTitle}>这本书没有打开</Text><Text style={styles.errorBody}>{state.error?.message}</Text><Pressable style={styles.retry} onPress={() => void open()}><Text style={styles.retryText}>重新尝试</Text></Pressable><Pressable onPress={() => navigation.goBack()}><Text style={styles.cancel}>返回书房</Text></Pressable></View>}
    {state.status === 'ready' && sessionReady && state.localUri && (book.format === 'epub' ? <Reader
      src={state.localUri}
      fileSystem={useEpubFileSystem}
      flow="paginated"
      spread="none"
      enableSwipe
      enableSelection={EPUB_READER_SELECTION_ENABLED}
      initialLocation={session.locator}
      onStarted={() => setRendererReady(false)}
      onReady={() => { setRendererReady(true); epub.injectJavascript(buildSelectionModeScript(false)); }}
      onNavigationLoaded={({ toc: navigationToc }) => setToc(flattenToc(navigationToc))}
      onLocationChange={(_total, location, progress, section) => persist(updateReadingProgress(sessionRef.current, {
        locator: String(location.start.cfi), progress, sectionTitle: section?.label,
      }))}
      onSelected={(quote, cfiRange) => {
        if (!shouldAcceptSelection(selectionMode, quote)) return;
        selectionDispatch({ type: 'SELECTED', quote, locator: String(cfiRange) });
      }}
      onWebViewMessage={(event: { type?: string; ok?: boolean; message?: string }) => {
        if (event.type !== 'readerNavigationResult') return;
        if (event.ok) {
          setDrawer(undefined);
          setNavigationMessage({ kind: 'success', text: '章节跳转成功' });
        } else {
          setNavigationMessage({ kind: 'error', text: event.message || '这个章节暂时无法跳转' });
        }
      }}
      onDisplayError={(reason) => failRender(`EPUB 排版失败：${reason || '书籍结构不兼容'}`)}
      renderLoadingFileComponent={() => <View style={styles.center}><ActivityIndicator color={colors.accent} /><Text style={styles.stage}>正在准备 EPUB 引擎…</Text></View>}
      renderOpeningBookComponent={() => <View style={styles.center}><ActivityIndicator color={colors.accent} /><Text style={styles.stage}>正在排版…</Text><Text style={styles.progress}>将从上次位置继续</Text></View>}
    /> : <Pdf
      ref={pdfRef}
      source={{ uri: state.localUri, cache: false }}
      style={styles.reader}
      page={session.page || 1}
      trustAllCerts={false}
      enableDoubleTapZoom
      onLoadComplete={(totalPages, _path, _size, tableContents) => {
        setRendererReady(true);
        setToc(flattenPdfToc(tableContents));
        persist(updateReadingProgress(sessionRef.current, { totalPages, progress: (sessionRef.current.page || 1) / totalPages }));
      }}
      onPageChanged={(page, totalPages) => persist(updateReadingProgress(sessionRef.current, { page, totalPages, progress: page / totalPages, sectionTitle: `第 ${page} 页` }))}
      onError={(error) => failRender(error instanceof Error ? error.message : 'PDF 渲染失败')}
    />)}

    {selection.mode === 'actions' && <View style={styles.selectionDock}>
      <View style={styles.selectionQuote}><Text style={styles.selectionLabel}>已选择</Text><Text numberOfLines={2} style={styles.selectionText}>{selection.quote}</Text></View>
      <Pressable style={styles.actionCompact} onPress={() => void copySelection()}><Text style={styles.actionGhostText}>{copied ? '已复制' : '复制'}</Text></Pressable>
      <Pressable style={styles.actionGhost} onPress={() => selectionDispatch({ type: 'ADD_NOTE' })}><Text style={styles.actionGhostText}>标注</Text></Pressable>
      <Pressable style={styles.actionPrimary} onPress={() => selectionDispatch({ type: 'ASK_AI' })}><Text style={styles.actionPrimaryText}>问 AI</Text></Pressable>
      <Pressable style={styles.closeDock} onPress={closeSelection}><Text style={styles.closeDockText}>×</Text></Pressable>
    </View>}

    <Modal visible={!!drawer} transparent animationType="slide" onRequestClose={() => setDrawer(undefined)}>
      <View style={styles.modalShade}><SafeAreaView style={styles.drawerSheet} edges={['bottom']}>
        <View style={styles.drawerHead}><View><Text style={styles.drawerKicker}>阅读导航</Text><Text style={styles.drawerTitle}>{drawer === 'toc' ? '目录' : '我的书签'}</Text></View><Pressable onPress={() => setDrawer(undefined)}><Text style={styles.drawerClose}>关闭</Text></Pressable></View>
        <View style={styles.drawerTabs}><Pressable onPress={() => setDrawer('toc')} style={[styles.drawerTab, drawer === 'toc' && styles.drawerTabActive]}><Text style={styles.drawerTabText}>目录 {toc.length}</Text></Pressable><Pressable onPress={() => setDrawer('bookmarks')} style={[styles.drawerTab, drawer === 'bookmarks' && styles.drawerTabActive]}><Text style={styles.drawerTabText}>书签 {bookmarks.length}</Text></Pressable></View>
        {navigationMessage && <View style={[styles.navMessage, navigationMessage.kind === 'error' && styles.navMessageError]}><Text style={styles.navMessageText}>{navigationMessage.text}</Text></View>}
        <ScrollView style={styles.navScroll} contentContainerStyle={styles.navList}>{(drawer === 'toc' ? toc : bookmarks).map((item) => <Pressable key={item.id} onPress={() => goTo(item)} style={[styles.navItem, { paddingLeft: 18 + item.depth * 18 }]}><Text numberOfLines={2} style={styles.navLabel}>{item.label}</Text><Text style={styles.navArrow}>›</Text></Pressable>)}{(drawer === 'toc' ? toc : bookmarks).length === 0 && <Text style={styles.emptyNav}>{drawer === 'toc' ? '这本书没有提供可识别的目录。' : '还没有书签。点击下方按钮，就能保存当前阅读位置。'}</Text>}</ScrollView>
        {drawer === 'bookmarks' && <View style={styles.bookmarkFooter}><Pressable disabled={!sessionReady} onPress={toggleCurrentBookmark} style={[styles.bookmarkAction, currentBookmarked && styles.bookmarkRemove]}><Text style={styles.bookmarkActionText}>{currentBookmarked ? '移除当前页书签' : '添加当前页书签'}</Text></Pressable><Text style={styles.bookmarkHint}>书签保存在本机，点击上方书签即可跳回原位置</Text></View>}
      </SafeAreaView></View>
    </Modal>

    <Modal visible={selection.mode === 'note'} transparent animationType="slide" onRequestClose={closeSelection}>
      <View style={styles.modalShade}><View style={styles.sheet}><Text style={styles.sheetKicker}>保存标注</Text><Text style={styles.quote} numberOfLines={5}>{selection.mode === 'note' ? selection.quote : ''}</Text><TextInput value={note} onChangeText={setNote} multiline placeholder="写下你的理解（可选）" placeholderTextColor={colors.muted} style={styles.noteInput} /><View style={styles.sheetActions}><Pressable onPress={closeSelection} style={styles.sheetCancel}><Text style={styles.sheetCancelText}>取消</Text></Pressable><Pressable disabled={saving} onPress={() => void saveNote()} style={styles.sheetSave}><Text style={styles.sheetSaveText}>{saving ? '保存中…' : '保存标注'}</Text></Pressable></View></View></View>
    </Modal>

    <Modal visible={selection.mode === 'ai'} transparent animationType="slide" onRequestClose={closeSelection}>
      <KeyboardAvoidingView style={styles.modalShade} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <View style={styles.aiSheet}>
          <View style={styles.aiHead}><View><Text style={styles.sheetKicker}>选文问 AI</Text><Text style={styles.aiTitle}>围绕原文继续追问</Text></View><Pressable onPress={closeSelection}><Text style={styles.drawerClose}>关闭</Text></Pressable></View>
          <ScrollView style={styles.aiThread} contentContainerStyle={styles.aiThreadContent} keyboardShouldPersistTaps="handled">
            <View style={styles.aiQuote}><Text numberOfLines={4} style={styles.aiQuoteText}>{selection.mode === 'ai' ? selection.quote : ''}</Text></View>
            {selection.mode === 'ai' && selection.messages.map((message) => <View key={message.id} style={[styles.aiMessage, message.role === 'user' ? styles.aiUserMessage : styles.aiAssistantMessage, message.failed && styles.aiFailedMessage]}>
              <Text selectable={message.role === 'assistant'} style={[styles.aiMessageText, message.role === 'user' && styles.aiUserMessageText]}>{message.content}</Text>
              {message.role === 'assistant' && !message.failed && <Pressable disabled={!!savingAnswerId || message.saved} onPress={() => void saveAnswer(message.id)} style={styles.inlineFavorite}><Text style={styles.inlineFavoriteText}>{message.saved ? '已收藏' : savingAnswerId === message.id ? '收藏中…' : '收藏这条回答'}</Text></Pressable>}
            </View>)}
            {asking && <View style={styles.aiLoading}><ActivityIndicator color={colors.accent} /><Text style={styles.progress}>正在结合此前对话思考…</Text></View>}
          </ScrollView>
          <View style={styles.aiComposer}><TextInput multiline value={aiQuestion} onChangeText={setAiQuestion} placeholder="继续追问这段原文…" placeholderTextColor={colors.muted} style={styles.aiInput} /><Pressable disabled={asking || !aiQuestion.trim()} onPress={sendFollowUp} style={[styles.aiSend, (asking || !aiQuestion.trim()) && styles.aiSendDisabled]}><Text style={styles.aiSendText}>发送</Text></Pressable></View>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.paper }, toolbar: { height: 66, paddingHorizontal: 8, flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderColor: colors.line, gap: 1 }, iconButton: { minWidth: 42, height: 40, paddingHorizontal: 4, borderRadius: 13, alignItems: 'center', justifyContent: 'center' }, backText: { fontSize: 38, color: colors.ink, lineHeight: 40 }, iconText: { color: colors.ink, fontSize: 11, fontWeight: '800' }, modeActive: { backgroundColor: colors.ink }, modeActiveText: { color: colors.paper }, bookmarkIndicator: { borderBottomWidth: 2, borderBottomColor: colors.accent }, titleWrap: { flex: 1, paddingHorizontal: 3 }, title: { color: colors.ink, fontFamily: typography.display, fontSize: 17 }, sub: { color: colors.muted, fontSize: 9, marginTop: 2 }, center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 34 }, stage: { color: colors.ink, fontFamily: typography.display, fontSize: 20, marginTop: 20 }, progress: { color: colors.muted, marginTop: 8 }, errorCode: { color: colors.accent, fontSize: 10, fontWeight: '800', letterSpacing: 1.8 }, errorTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 28, marginTop: 13 }, errorBody: { color: colors.muted, fontSize: 15, lineHeight: 24, textAlign: 'center', marginTop: 12 }, retry: { marginTop: 26, backgroundColor: colors.accent, borderRadius: 24, paddingHorizontal: 28, paddingVertical: 13 }, retryText: { color: colors.white, fontWeight: '800' }, cancel: { color: colors.muted, marginTop: 20 }, reader: { flex: 1, backgroundColor: colors.paper },
  selectionModeBanner: { backgroundColor: colors.ink, paddingVertical: 7, paddingHorizontal: 14, alignItems: 'center' }, selectionModeText: { color: colors.paper, fontSize: 10 }, selectionDock: { position: 'absolute', left: 10, right: 10, bottom: 12, minHeight: 74, backgroundColor: colors.ink, borderRadius: 19, padding: 10, flexDirection: 'row', alignItems: 'center', gap: 6, shadowColor: '#000', shadowOpacity: .2, shadowRadius: 12, elevation: 8 }, selectionQuote: { flex: 1, paddingLeft: 5 }, selectionLabel: { color: '#D5C3B1', fontSize: 9, fontWeight: '900', letterSpacing: 1.3 }, selectionText: { color: colors.paper, fontSize: 11, lineHeight: 16, marginTop: 3 }, actionCompact: { borderWidth: 1, borderColor: '#73685F', borderRadius: 14, paddingHorizontal: 8, paddingVertical: 10 }, actionGhost: { borderWidth: 1, borderColor: '#73685F', borderRadius: 14, paddingHorizontal: 9, paddingVertical: 10 }, actionGhostText: { color: colors.paper, fontSize: 10, fontWeight: '800' }, actionPrimary: { backgroundColor: colors.accent, borderRadius: 14, paddingHorizontal: 10, paddingVertical: 10 }, actionPrimaryText: { color: colors.white, fontSize: 10, fontWeight: '900' }, closeDock: { width: 20, alignItems: 'center' }, closeDockText: { color: '#B9ACA0', fontSize: 20 },
  modalShade: { flex: 1, backgroundColor: 'rgba(20,17,14,.45)', justifyContent: 'flex-end' }, drawerSheet: { height: '78%', backgroundColor: colors.paper, borderTopLeftRadius: 26, borderTopRightRadius: 26, overflow: 'hidden' }, drawerHead: { padding: 20, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }, drawerKicker: { color: colors.accent, fontSize: 10, fontWeight: '900', letterSpacing: 1.6 }, drawerTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 27, marginTop: 3 }, drawerClose: { color: colors.accent, fontWeight: '800' }, drawerTabs: { flexDirection: 'row', marginHorizontal: 18, borderBottomWidth: 1, borderColor: colors.line }, drawerTab: { flex: 1, paddingVertical: 12, alignItems: 'center' }, drawerTabActive: { borderBottomWidth: 2, borderColor: colors.accent }, drawerTabText: { color: colors.ink, fontWeight: '800', fontSize: 12 }, navMessage: { marginHorizontal: 18, marginTop: 10, borderRadius: 10, backgroundColor: colors.accentSoft, paddingHorizontal: 12, paddingVertical: 9 }, navMessageError: { backgroundColor: '#F1D8D1' }, navMessageText: { color: colors.ink, fontSize: 11 }, navScroll: { flex: 1 }, navList: { paddingVertical: 8, paddingBottom: 30 }, navItem: { minHeight: 52, borderBottomWidth: 1, borderColor: colors.line, paddingRight: 18, paddingVertical: 13, flexDirection: 'row', alignItems: 'center' }, navLabel: { color: colors.ink, flex: 1, fontSize: 14, lineHeight: 20 }, navArrow: { color: colors.accent, fontSize: 22 }, emptyNav: { color: colors.muted, textAlign: 'center', lineHeight: 23, padding: 40 }, bookmarkFooter: { padding: 16, borderTopWidth: 1, borderTopColor: colors.line, backgroundColor: colors.white }, bookmarkAction: { backgroundColor: colors.accent, borderRadius: 15, padding: 14, alignItems: 'center' }, bookmarkRemove: { backgroundColor: colors.ink }, bookmarkActionText: { color: colors.white, fontWeight: '900' }, bookmarkHint: { color: colors.muted, textAlign: 'center', fontSize: 10, marginTop: 8 },
  sheet: { backgroundColor: colors.paper, borderTopLeftRadius: 26, borderTopRightRadius: 26, padding: 24, paddingBottom: 34 }, sheetKicker: { color: colors.accent, fontSize: 10, fontWeight: '900', letterSpacing: 1.5 }, quote: { color: colors.ink, fontFamily: typography.display, fontSize: 19, lineHeight: 28, marginTop: 13 }, noteInput: { minHeight: 90, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 14, padding: 14, marginTop: 18, color: colors.ink, textAlignVertical: 'top' }, sheetActions: { flexDirection: 'row', gap: 10, marginTop: 14 }, sheetCancel: { flex: 1, borderWidth: 1, borderColor: colors.line, borderRadius: 14, padding: 14, alignItems: 'center' }, sheetCancelText: { color: colors.ink, fontWeight: '700' }, sheetSave: { flex: 1, backgroundColor: colors.accent, borderRadius: 14, padding: 14, alignItems: 'center' }, sheetSaveText: { color: colors.white, fontWeight: '800' },
  aiSheet: { height: '82%', backgroundColor: colors.paper, borderTopLeftRadius: 26, borderTopRightRadius: 26, paddingTop: 20, overflow: 'hidden' }, aiHead: { paddingHorizontal: 20, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }, aiTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 24, marginTop: 3 }, aiThread: { flex: 1 }, aiThreadContent: { paddingHorizontal: 20, paddingBottom: 12, gap: 10 }, aiQuote: { backgroundColor: colors.paperDeep, borderLeftWidth: 3, borderColor: colors.accent, borderRadius: 12, padding: 14, marginTop: 18, marginBottom: 6 }, aiQuoteText: { color: colors.muted, fontFamily: typography.display, fontSize: 15, lineHeight: 23 }, aiLoading: { minHeight: 90, justifyContent: 'center', alignItems: 'center' }, aiMessage: { maxWidth: '91%', borderRadius: 16, padding: 13 }, aiUserMessage: { alignSelf: 'flex-end', backgroundColor: colors.ink, borderBottomRightRadius: 5 }, aiAssistantMessage: { alignSelf: 'flex-start', backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderBottomLeftRadius: 5 }, aiFailedMessage: { borderColor: '#C98B76', backgroundColor: '#F4E2DA' }, aiMessageText: { color: colors.ink, fontSize: 15, lineHeight: 24 }, aiUserMessageText: { color: colors.paper }, inlineFavorite: { alignSelf: 'flex-start', marginTop: 9, borderTopWidth: 1, borderTopColor: colors.line, paddingTop: 8 }, inlineFavoriteText: { color: colors.accent, fontSize: 10, fontWeight: '900' }, aiComposer: { flexDirection: 'row', alignItems: 'flex-end', gap: 8, padding: 12, paddingBottom: 18, borderTopWidth: 1, borderTopColor: colors.line, backgroundColor: colors.white }, aiInput: { flex: 1, minHeight: 44, maxHeight: 96, backgroundColor: colors.paper, borderRadius: 14, paddingHorizontal: 13, paddingVertical: 11, color: colors.ink, textAlignVertical: 'top' }, aiSend: { height: 44, borderRadius: 14, backgroundColor: colors.accent, paddingHorizontal: 17, alignItems: 'center', justifyContent: 'center' }, aiSendDisabled: { opacity: .42 }, aiSendText: { color: colors.white, fontWeight: '900' },
});
