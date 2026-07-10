import { api } from '../api/client';
import { useAuthStore } from '../../state/authStore';
import {
  dbClient,
  getMeta,
  setMeta,
  upsertBook,
  upsertAnnotation,
  upsertFlashcard,
  upsertReadingLog,
  upsertCompanionMessage,
  upsertPersona,
  type BookRow,
  type AnnotationRow,
  type FlashcardRow,
  type ReadingLogRow,
  type CompanionMessageRow,
  type PersonaRow,
} from '../storage/db';
import type {
  Book,
  Annotation,
  Flashcard,
  ReadingLog,
  CompanionMessage,
  Persona,
  SyncPullResponse,
} from '../../types/models';

/**
 * 同步引擎（阶段 2 · 离线优先闭环）
 * ──────────────────────────────────────────────────────────────────
 * 模型：服务端权威 + 设备镜像。
 * - Pull：GET /v1/sync?since=游标，按 updated_at 增量下发（含软删墓碑）。
 *   逐表 LWW（最后写入获胜）合并：本地有更新则不覆盖；否则 upsert。
 * - Push：离线写操作进 mutation_queue，联网后按序重放（带幂等删除）。
 * - 单用户单记录极少并发编辑，LWW 足够稳；冲突统一以 updated_at 大者胜。
 */
const LAST_PULL_KEY = 'last_pull';

// ── snake_case（服务端）→ camelCase（本地 upsert）映射 ──
function toBookRow(b: Book): Partial<BookRow> {
  return {
    id: b.id, userId: b.user_id, title: b.title, author: b.author, format: b.format,
    size: b.size, coverPath: b.cover_path, coverUrl: b.cover_url, coverGradient: b.cover_gradient,
    fileUrl: b.file_url, mindmapMd: b.mindmap_md, conceptGraphStatus: b.concept_graph_status,
    characterGraphStatus: b.character_graph_status, argumentMapStatus: b.argument_map_status,
    createdAt: b.created_at, updatedAt: b.updated_at, deletedAt: b.deleted_at,
  };
}
function toAnnotationRow(a: Annotation): Partial<AnnotationRow> {
  return {
    id: a.id, bookId: a.book_id, userId: a.user_id, loc: a.loc, quote: a.quote,
    tag: a.tag, note: a.note, createdAt: a.created_at, updatedAt: a.updated_at, deletedAt: a.deleted_at,
  };
}
function toFlashcardRow(f: Flashcard): Partial<FlashcardRow> {
  return {
    id: f.id, userId: f.user_id, bookId: f.book_id, annotationId: f.annotation_id,
    front: f.front, back: f.back, box: f.box, dueDate: f.due_date,
    createdAt: f.created_at, updatedAt: f.updated_at, deletedAt: f.deleted_at,
  };
}
function toReadingLogRow(r: ReadingLog): Partial<ReadingLogRow> {
  return {
    id: r.id, userId: r.user_id, bookId: r.book_id, logDate: r.log_date, seconds: r.seconds,
    createdAt: r.created_at, updatedAt: r.updated_at, deletedAt: r.deleted_at,
  };
}
function toCompanionRow(m: CompanionMessage): Partial<CompanionMessageRow> {
  return {
    id: m.id, userId: m.user_id, personaId: m.persona_id, scope: m.scope, bookId: m.book_id,
    role: m.role, content: m.content, createdAt: m.created_at, updatedAt: m.updated_at, deletedAt: m.deleted_at,
  };
}
function toPersonaRow(p: Persona): Partial<PersonaRow> {
  return {
    id: p.id, userId: p.user_id, name: p.name, emoji: p.emoji, description: p.description,
    systemPrompt: p.system_prompt, isDefault: p.is_default ? 1 : 0,
    createdAt: p.created_at, updatedAt: p.updated_at, deletedAt: p.deleted_at,
  };
}

/** LWW：本地更新时间 >= 远端则不覆盖（保留未推送的本地编辑） */
async function lwwApply(
  table: string,
  id: number,
  remoteUpdated: string,
  upsertFn: (row: any) => Promise<void>,
  row: any
) {
  const local = await dbClient.getFirstAsync<{ updated_at: string }>(
    `SELECT updated_at FROM ${table} WHERE id = ?`,
    [id]
  );
  if (local && local.updated_at >= remoteUpdated) return;
  await upsertFn(row);
}

export async function pullSince(): Promise<void> {
  const since = await getMeta(LAST_PULL_KEY);
  const data = await api.get<SyncPullResponse>(
    `/sync${since ? `?since=${encodeURIComponent(since)}` : ''}`
  );

  for (const b of data.books as Book[]) {
    await lwwApply('books', b.id, b.updated_at, (r) => upsertBook(toBookRow(r)), b);
  }
  for (const a of data.annotations as Annotation[]) {
    await lwwApply('annotations', a.id, a.updated_at, (r) => upsertAnnotation(toAnnotationRow(r)), a);
  }
  for (const f of data.flashcards as Flashcard[]) {
    await lwwApply('flashcards', f.id, f.updated_at, (r) => upsertFlashcard(toFlashcardRow(r)), f);
  }
  for (const r of data.reading_logs as ReadingLog[]) {
    await lwwApply('reading_logs', r.id, r.updated_at, (rr) => upsertReadingLog(toReadingLogRow(rr)), r);
  }
  for (const m of data.companion_messages as CompanionMessage[]) {
    await lwwApply('companion_messages', m.id, m.updated_at, (rr) => upsertCompanionMessage(toCompanionRow(rr)), m);
  }
  for (const p of data.personas as Persona[]) {
    await lwwApply('personas', p.id, p.updated_at, (rr) => upsertPersona(toPersonaRow(rr)), p);
  }

  await setMeta(LAST_PULL_KEY, data.server_time);
}

// ── 离线写队列 ──
export interface Mutation {
  id?: number;
  table: 'annotations' | 'flashcards' | 'reading_logs' | 'books';
  op: 'insert' | 'update' | 'delete';
  /** JSON 字符串；可含 localId（乐观写入时的本地临时 id，用于推送成功后回填） */
  payload: string;
  createdAt: string;
}

export async function enqueueMutation(m: Omit<Mutation, 'id' | 'createdAt'>) {
  await dbClient.runAsync(
    `INSERT INTO mutation_queue (table_name, op, payload, created_at)
     VALUES (?,?,?,?)`,
    [m.table, m.op, m.payload, new Date().toISOString()]
  );
}

/** 把离线队列里的写操作重放到真实端点（幂等：成功即删行） */
export async function flushQueue(): Promise<void> {
  const rows = await dbClient.getAllAsync<Mutation & { id: number }>(
    'SELECT * FROM mutation_queue ORDER BY created_at ASC'
  );
  const userId = useAuthStore.getState().user?.id;

  for (const row of rows) {
    try {
      await pushOne(row, userId);
      await dbClient.runAsync('DELETE FROM mutation_queue WHERE id = ?', [row.id]);
    } catch (e) {
      // 失败保留，下次重试（可加重试上限/死信队列）
      console.warn('[sync] flush failed for', row.table, e);
    }
  }
}

async function pushOne(row: Mutation & { id: number }, userId?: number) {
  const body = JSON.parse(row.payload) as Record<string, any>;

  switch (row.table) {
    case 'annotations': {
      if (row.op === 'delete') {
        await api.del(`/books/${body.book_id}/annotations/${body.id}`);
      } else {
        const res = await api.post<{ ok: boolean; id: number }>(
          `/books/${body.book_id}/annotations`,
          { loc: body.loc, quote: body.quote, tag: body.tag, note: body.note }
        );
        if (body.localId != null) {
          await dbClient.runAsync('DELETE FROM annotations WHERE id = ?', [body.localId]);
        }
        await upsertAnnotation({
          id: res.id, bookId: body.book_id, userId, loc: body.loc, quote: body.quote,
          tag: body.tag, note: body.note,
          createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
        });
      }
      break;
    }
    case 'flashcards': {
      if (row.op === 'delete') {
        await api.del(`/flashcards/${body.id}`);
      } else if (row.op === 'update') {
        // 间隔重复复习：POST /v1/flashcards/{id}/review
        await api.post(`/flashcards/${body.id}/review`, { known: body.known });
      } else {
        const res = await api.post<{ ok: boolean; id: number }>(
          `/books/${body.book_id}/flashcards`,
          { quote: body.quote }
        );
        if (body.localId != null) {
          await dbClient.runAsync('DELETE FROM flashcards WHERE id = ?', [body.localId]);
        }
        await upsertFlashcard({
          id: res.id, userId, bookId: body.book_id,
          front: body.quote, back: '', box: 1, dueDate: new Date().toISOString().slice(0, 10),
          createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
        });
      }
      break;
    }
    case 'reading_logs': {
      await api.post('/reading/log', { book_id: body.book_id, seconds: body.seconds });
      break;
    }
    default:
      // 不支持的表：直接丢弃，避免队列卡死
      console.warn('[sync] unknown mutation table', row.table);
  }
}

/** 前台/启动时对齐：先推后拉（保证离线写入先上行，再拉取最新镜像） */
export async function syncNow(): Promise<void> {
  await flushQueue();
  await pullSince();
}
