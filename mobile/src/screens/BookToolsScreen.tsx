import { useMemo, useState } from 'react';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api } from '../api/client';
import type { RootStackParamList } from '../navigation/AppNavigator';
import { normalizeToolResult, type ToolKind, type ToolResult } from '../reader/reliability';
import { buildToolIndex, focusToolItem } from '../reader/toolPresentation';
import { colors, typography } from '../theme';

type Props = NativeStackScreenProps<RootStackParamList, 'BookTools'>;
type Tool = { kind: ToolKind; title: string; description: string; run: (id: number) => Promise<unknown> };
type Focusable = Extract<ToolResult, { kind: 'concept' | 'characters' | 'argument' }>;

const bullets = (value: string) => value.split('\n').map((line) => line.replace(/^\s*[-#•]+\s*/, '').trim()).filter(Boolean);

function Empty({ children }: { children: string }) {
  return <View style={styles.empty}><Text style={styles.emptyText}>{children}</Text></View>;
}

function SummaryView({ result }: { result: Extract<ToolResult, { kind: 'summary' }> }) {
  return <><View style={styles.coverage}><Text style={styles.coverageCount}>{result.chapters.length}</Text><View><Text style={styles.coverageTitle}>个章节已完成总结</Text><Text style={styles.coverageSub}>按原书章节顺序完整呈现</Text></View></View>{result.chapters.map((chapter, index) => <View key={`${chapter.title}-${index}`} style={styles.resultCard}><Text style={styles.overline}>CHAPTER {String(index + 1).padStart(2, '0')}</Text><Text style={styles.cardHeading}>{chapter.title}</Text>{bullets(chapter.summary).map((line, item) => <View key={`${line}-${item}`} style={styles.bulletRow}><View style={styles.bullet} /><Text style={styles.body}>{line}</Text></View>)}</View>)}</>;
}

function FocusedToolView({ result }: { result: Focusable }) {
  const [query, setQuery] = useState('');
  const index = useMemo(() => buildToolIndex(result, query), [query, result]);
  const [selectedId, setSelectedId] = useState('');
  const activeId = index.some((item) => item.id === selectedId) ? selectedId : index[0]?.id;
  const focus = activeId ? focusToolItem(result, activeId) : undefined;
  const labels = result.kind === 'concept'
    ? { count: '核心概念', search: '搜索概念、解释或章节', detail: '概念解释', metric: '次出现' }
    : result.kind === 'characters'
      ? { count: '人物角色', search: '搜索人物、阵营或简介', detail: '人物档案', metric: '条关系' }
      : { count: '主要论点', search: '搜索主张或论点类型', detail: '论证拆解', metric: '条证据' };
  return <>
    <View style={styles.overviewCard}><Text style={styles.overviewNumber}>{buildToolIndex(result, '').length}</Text><View style={{ flex: 1 }}><Text style={styles.overviewTitle}>{labels.count}</Text><Text style={styles.overviewText}>先选择一项，再阅读它的解释与直接关系</Text></View></View>
    <TextInput value={query} onChangeText={setQuery} placeholder={labels.search} placeholderTextColor={colors.muted} style={styles.search} />
    <Text style={styles.sectionTitle}>索引</Text>
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.indexRail}>
      {index.map((item, indexNumber) => <Pressable key={item.id} onPress={() => setSelectedId(item.id)} style={[styles.indexCard, activeId === item.id && styles.indexCardActive]}><Text style={[styles.indexNumber, activeId === item.id && styles.indexNumberActive]}>{String(indexNumber + 1).padStart(2, '0')}</Text><Text numberOfLines={2} style={[styles.indexTitle, activeId === item.id && styles.indexTitleActive]}>{item.title}</Text><Text numberOfLines={1} style={[styles.indexSub, activeId === item.id && styles.indexSubActive]}>{item.subtitle || `${item.metric} ${labels.metric}`}</Text></Pressable>)}
    </ScrollView>
    {!index.length && <Empty>没有找到匹配内容，请换一个关键词。</Empty>}
    {focus && <View style={styles.focusCard}>
      <Text style={styles.focusKicker}>{labels.detail}</Text><Text style={styles.focusTitle}>{focus.title}</Text>{!!focus.subtitle && <Text style={styles.focusMeta}>{focus.subtitle}</Text>}{!!focus.description && <Text style={styles.focusBody}>{focus.description}</Text>}
      {!!focus.related.length && <><Text style={styles.subsectionTitle}>直接关系</Text>{focus.related.map((relation, relationIndex) => <View key={`${relation.from}-${relation.to}-${relationIndex}`} style={styles.relationCard}><View style={styles.relationLine}><Text style={styles.relationPerson}>{relation.from}</Text><View style={styles.relationPill}><Text style={styles.relationPillText}>{relation.label}</Text></View><Text style={styles.relationPerson}>{relation.to}</Text></View>{!!relation.description && <Text style={styles.relationDesc}>{relation.description}</Text>}</View>)}</>}
      {!!focus.evidence.length && <><Text style={styles.subsectionTitle}>支撑证据</Text>{focus.evidence.map((item, indexNumber) => <View key={indexNumber} style={styles.evidence}><Text style={styles.evidenceType}>{item.type}</Text><Text style={styles.body}>{item.text}</Text></View>)}</>}
      {!!focus.counters.length && <><Text style={styles.subsectionTitle}>反方与例外</Text>{focus.counters.map((item, indexNumber) => <View key={indexNumber} style={styles.counter}><Text style={styles.counterType}>{item.type}</Text><Text style={styles.body}>{item.text}</Text></View>)}</>}
      {!!focus.challenge && <View style={styles.challenge}><Text style={styles.challengeTitle}>批判性追问</Text><Text style={styles.body}>{focus.challenge}</Text></View>}
    </View>}
  </>;
}

function QuizView({ result }: { result: Extract<ToolResult, { kind: 'quiz' }> }) {
  const [answers, setAnswers] = useState<Record<number, number>>({});
  return <>{result.questions.map((question, index) => { const selected = answers[question.id]; return <View key={question.id} style={styles.resultCard}><Text style={styles.overline}>QUESTION {index + 1} / {result.questions.length}</Text><Text style={styles.cardHeading}>{question.stem}</Text>{question.options.map((option, optionIndex) => { const revealed = selected !== undefined; const correct = optionIndex === question.answer; const chosen = selected === optionIndex; return <Pressable key={optionIndex} onPress={() => setAnswers((items) => ({ ...items, [question.id]: optionIndex }))} style={[styles.option, revealed && correct && styles.optionCorrect, revealed && chosen && !correct && styles.optionWrong]}><Text style={styles.optionLetter}>{String.fromCharCode(65 + optionIndex)}</Text><Text style={styles.optionText}>{option}</Text></Pressable>; })}{selected !== undefined && <View style={styles.explanation}><Text style={styles.explanationTitle}>{selected === question.answer ? '回答正确' : `正确答案：${String.fromCharCode(65 + question.answer)}`}</Text><Text style={styles.body}>{question.reason}</Text></View>}</View>; })}</>;
}

function ResultBody({ result }: { result: ToolResult }) {
  if (result.kind === 'error') return <Empty>{result.message}</Empty>;
  if (result.kind === 'summary') return <SummaryView result={result} />;
  if (result.kind === 'concept' || result.kind === 'characters' || result.kind === 'argument') return <FocusedToolView result={result} />;
  if (result.kind === 'quiz') return <QuizView result={result} />;
  return <View style={styles.markdownCard}><Text selectable style={styles.markdown}>{result.markdown}</Text><Text style={styles.filename}>{result.filename}</Text></View>;
}

export function BookToolsScreen({ route, navigation }: Props) {
  const { book } = route.params;
  const [busy, setBusy] = useState('');
  const [result, setResult] = useState<{ title: string; value: ToolResult }>();
  const tools: Tool[] = [
    { kind: 'summary', title: '章节总结', description: '逐章提炼要点，并标明实际完成的章节数量。', run: api.analyzeBook },
    { kind: 'concept', title: '概念关系', description: '搜索核心概念，逐个查看定义和直接关联。', run: api.conceptGraph },
    { kind: 'characters', title: '人物关系', description: '从人物索引进入，只看与当前人物相关的关系。', run: api.characterGraph },
    { kind: 'argument', title: '论证结构', description: '以主张为入口，分层阅读证据、反方与追问。', run: api.argumentMap },
    { kind: 'quiz', title: '理解测验', description: '根据书籍内容生成可即时作答的理解测验。', run: api.quizBook },
    { kind: 'markdown', title: '导出笔记', description: '导出适合长期保存到 Obsidian 的 Markdown。', run: api.exportMarkdown },
  ];
  const run = async (tool: Tool) => {
    setBusy(tool.title);
    try { setResult({ title: tool.title, value: normalizeToolResult(tool.kind, await tool.run(book.id)) }); }
    catch (error) { setResult({ title: tool.title, value: { kind: 'error', message: error instanceof Error ? error.message : '执行失败，请稍后重试。' } }); }
    finally { setBusy(''); }
  };
  return <SafeAreaView style={styles.safe} edges={['top']}><View style={styles.toolbar}><Pressable onPress={() => navigation.goBack()}><Text style={styles.back}>‹</Text></Pressable><View><Text style={styles.toolbarTitle}>阅读工具</Text><Text style={styles.toolbarSub} numberOfLines={1}>{book.title}</Text></View></View><ScrollView contentContainerStyle={styles.page}><Text style={styles.eyebrow}>READ DEEPER</Text><Text style={styles.title}>把复杂分析变成可读的路径</Text><Text style={styles.intro}>手机屏幕不适合一次铺开整张图。先找到概念、人物或主张，再进入它的局部关系和证据。</Text>{tools.map((tool, index) => <Pressable key={tool.title} disabled={!!busy} onPress={() => void run(tool)} style={styles.card}><Text style={styles.toolIndex}>{String(index + 1).padStart(2, '0')}</Text><View style={styles.cardBody}><Text style={styles.cardTitle}>{tool.title}</Text><Text style={styles.cardDescription}>{tool.description}</Text></View>{busy === tool.title ? <ActivityIndicator color={colors.accent} /> : <Text style={styles.arrow}>›</Text>}</Pressable>)}</ScrollView><Modal visible={!!result} animationType="slide" onRequestClose={() => setResult(undefined)}><SafeAreaView style={styles.resultSafe}><View style={styles.resultHead}><Pressable onPress={() => setResult(undefined)}><Text style={styles.close}>关闭</Text></Pressable><Text style={styles.resultTitle}>{result?.title}</Text><View style={{ width: 36 }} /></View><ScrollView contentContainerStyle={styles.resultPage}>{result && <ResultBody result={result.value} />}</ScrollView></SafeAreaView></Modal></SafeAreaView>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.paper }, toolbar: { height: 66, borderBottomWidth: 1, borderColor: colors.line, flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16 }, back: { color: colors.ink, fontSize: 38, lineHeight: 42 }, toolbarTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 18 }, toolbarSub: { color: colors.muted, fontSize: 10, maxWidth: 260 }, page: { padding: 22, paddingBottom: 45 }, eyebrow: { color: colors.accent, fontSize: 10, fontWeight: '800', letterSpacing: 2.2 }, title: { color: colors.ink, fontFamily: typography.display, fontSize: 29, lineHeight: 39, marginTop: 8 }, intro: { color: colors.muted, lineHeight: 22, marginTop: 9, marginBottom: 18 }, card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 18, padding: 17, marginTop: 11, flexDirection: 'row', alignItems: 'center', gap: 14 }, toolIndex: { color: colors.accent, fontFamily: typography.display, fontSize: 16 }, cardBody: { flex: 1 }, cardTitle: { color: colors.ink, fontWeight: '800', fontSize: 16 }, cardDescription: { color: colors.muted, lineHeight: 19, marginTop: 5, fontSize: 12 }, arrow: { color: colors.accent, fontSize: 24 }, resultSafe: { flex: 1, backgroundColor: colors.paper }, resultHead: { height: 62, borderBottomWidth: 1, borderColor: colors.line, paddingHorizontal: 18, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }, close: { color: colors.accent, fontWeight: '800' }, resultTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 18 }, resultPage: { padding: 18, paddingBottom: 48 },
  coverage: { backgroundColor: colors.ink, borderRadius: 20, padding: 20, marginBottom: 14, flexDirection: 'row', alignItems: 'center', gap: 16 }, coverageCount: { color: colors.paper, fontFamily: typography.display, fontSize: 40 }, coverageTitle: { color: colors.paper, fontWeight: '900' }, coverageSub: { color: '#BEB2A6', fontSize: 11, marginTop: 3 }, resultCard: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 18, padding: 18, marginBottom: 13 }, overline: { color: colors.accent, fontSize: 9, fontWeight: '900', letterSpacing: 1.6 }, cardHeading: { color: colors.ink, fontFamily: typography.display, fontSize: 21, lineHeight: 29, marginTop: 7 }, body: { color: colors.ink, fontSize: 14, lineHeight: 22, flex: 1 }, bulletRow: { flexDirection: 'row', gap: 10, alignItems: 'flex-start', marginTop: 10 }, bullet: { width: 5, height: 5, borderRadius: 3, backgroundColor: colors.accent, marginTop: 8 },
  overviewCard: { backgroundColor: colors.ink, borderRadius: 20, padding: 18, flexDirection: 'row', alignItems: 'center', gap: 15 }, overviewNumber: { color: colors.paper, fontFamily: typography.display, fontSize: 38 }, overviewTitle: { color: colors.paper, fontWeight: '900', fontSize: 15 }, overviewText: { color: '#BEB2A6', fontSize: 11, marginTop: 4, lineHeight: 16 }, search: { height: 48, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 15, paddingHorizontal: 15, color: colors.ink, marginTop: 14 }, sectionTitle: { color: colors.muted, fontSize: 10, fontWeight: '900', letterSpacing: 1.6, marginTop: 20, marginBottom: 9 }, indexRail: { gap: 9, paddingRight: 18 }, indexCard: { width: 142, minHeight: 112, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 16, padding: 13 }, indexCardActive: { backgroundColor: colors.accent, borderColor: colors.accent }, indexNumber: { color: colors.accent, fontFamily: typography.display, fontSize: 13 }, indexNumberActive: { color: '#F4D5C8' }, indexTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 17, lineHeight: 22, marginTop: 7 }, indexTitleActive: { color: colors.white }, indexSub: { color: colors.muted, fontSize: 10, marginTop: 'auto', paddingTop: 7 }, indexSubActive: { color: '#F4D5C8' },
  focusCard: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 20, padding: 19, marginTop: 17 }, focusKicker: { color: colors.accent, fontSize: 9, fontWeight: '900', letterSpacing: 1.6 }, focusTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 25, lineHeight: 33, marginTop: 7 }, focusMeta: { color: colors.muted, fontSize: 11, marginTop: 4 }, focusBody: { color: colors.ink, fontSize: 15, lineHeight: 24, marginTop: 15 }, subsectionTitle: { color: colors.ink, fontWeight: '900', fontSize: 12, marginTop: 22, marginBottom: 8 }, relationCard: { backgroundColor: colors.paper, borderRadius: 14, padding: 12, marginTop: 8 }, relationLine: { flexDirection: 'row', alignItems: 'center', gap: 7 }, relationPerson: { color: colors.ink, fontWeight: '800', flex: 1 }, relationPill: { borderRadius: 10, backgroundColor: colors.accentSoft, paddingHorizontal: 8, paddingVertical: 4 }, relationPillText: { color: colors.accent, fontSize: 9, fontWeight: '900' }, relationDesc: { color: colors.muted, fontSize: 11, lineHeight: 17, marginTop: 8 }, evidence: { borderLeftWidth: 3, borderLeftColor: '#688060', paddingLeft: 12, marginTop: 12 }, evidenceType: { color: '#587052', fontSize: 10, fontWeight: '800', marginBottom: 4 }, counter: { borderLeftWidth: 3, borderLeftColor: colors.accent, paddingLeft: 12, marginTop: 12 }, counterType: { color: colors.accent, fontSize: 10, fontWeight: '800', marginBottom: 4 }, challenge: { backgroundColor: colors.paperDeep, borderRadius: 12, padding: 13, marginTop: 18 }, challengeTitle: { color: colors.accent, fontSize: 10, fontWeight: '900', marginBottom: 5 },
  option: { borderWidth: 1, borderColor: colors.line, borderRadius: 13, padding: 13, marginTop: 10, flexDirection: 'row', gap: 10, alignItems: 'center' }, optionCorrect: { backgroundColor: '#E2ECDD', borderColor: '#7D9874' }, optionWrong: { backgroundColor: '#F4DDD7', borderColor: colors.accent }, optionLetter: { color: colors.accent, fontWeight: '900' }, optionText: { color: colors.ink, flex: 1 }, explanation: { backgroundColor: colors.paper, borderRadius: 12, padding: 13, marginTop: 13 }, explanationTitle: { color: colors.accent, fontWeight: '900', marginBottom: 5 }, markdownCard: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 18, padding: 18 }, markdown: { color: colors.ink, fontSize: 14, lineHeight: 23 }, filename: { color: colors.muted, fontSize: 10, marginTop: 20 }, empty: { minHeight: 180, alignItems: 'center', justifyContent: 'center', padding: 28 }, emptyText: { color: colors.muted, textAlign: 'center', fontSize: 15, lineHeight: 24 },
});
