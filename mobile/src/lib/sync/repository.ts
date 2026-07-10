import { dbClient, upsertAnnotation, upsertFlashcard, upsertReadingLog, upsertCompanionMessage } from '../storage/db';
import { useAuthStore } from '../../state/authStore';
import { enqueueMutation } from './engine';
import * as services from '../services';
import type {
  Book,
  Annotation,
  Flashcard,
  ReadingLog,
  CompanionMessage,
  ReadingStats,
} from '../../types/models';

/**
 * 本地优先仓储层（阶段 2）
 * ──────────────────────────────────────────────────────────────────
 * 原则（第一性原理：离线可用 + 多端一致）：
 *  - 读：一律来自本地 SQLite（离线即可见），由同步引擎从云端填充。
 *  - 写：先乐观写入本地（UI 立即更新），再尝试上行：
 *      · 在线 → 调 services 真实端点，成功后用服务端 id 回填本地；
 *      · 离线/失败 → 进 mutation_queue，下次 syncNow 重放。
 * 书籍由电脑端导入，移动端只「读」，不新建。
 */

const BOOK_COLS = `id,user_id,title,author,format,size,cover_path,cover_url,cover_gradient,
  file_url,mindmap_md,concept_graph_status,character_graph_status,argument_map_status,
  created_at,updated_at,deleted_at`;

function currentUserId(): number | undefined {
  return useAuthStore.getState().user?.id;
}

// ── 书籍（只读）──
export const booksRepo = {
  list(): Promise<Book[]> {
    return dbClient.getAllAsync<Book>(
      `SELECT ${BOOK_COLS} FROM books WHERE deleted_at IS NULL ORDER BY updated_at DESC`
    );
  },
  get(id: number | string): Promise<Book | null> {
    return dbClient.getFirstAsync<Book>(
      `SELECT ${BOOK_COLS} FROM books WHERE id = ? AND deleted_at IS NULL`,
      [id]
    );
  },
};

// ── 划线 ──
export const annotationsRepo = {
  list(bookId: number | string): Promise<Annotation[]> {
    return dbClient.getAllAsync<Annotation>(
      `SELECT id,book_id,user_id,loc,quote,tag,note,created_at,updated_at,deleted_at
       FROM annotations WHERE book_id = ? AND deleted_at IS NULL ORDER BY loc`,
      [bookId]
    );
  },
  async create(bookId: number | string, payload: { loc: string; quote: string; tag?: string; note?: string }) {
    const now = new Date().toISOString();
    const uid = currentUserId();
    const localId = -Date.now(); // 临时本地 id（负数，避免与 server id 冲突）
    const optimistic: Annotation = {
      id: localId, book_id: Number(bookId), user_id: uid ?? 0,
      loc: payload.loc, quote: payload.quote, tag: payload.tag ?? null, note: payload.note ?? null,
      created_at: now, updated_at: now, deleted_at: null,
    };
    await upsertAnnotation(optimistic);

    try {
      const res = await services.addAnnotation(bookId, payload);
      await dbClient.runAsync('DELETE FROM annotations WHERE id = ?', [localId]);
      await upsertAnnotation({
        ...optimistic, id: res.id, updated_at: new Date().toISOString(),
      });
      return res.id;
    } catch {
      // 离线：入队，标记 localId 以便推送成功后回填
      await enqueueMutation({
        table: 'annotations',
        op: 'insert',
        payload: JSON.stringify({ ...payload, book_id: Number(bookId), localId }),
      });
      return localId;
    }
  },
};

// ── 闪卡 ──
export const flashcardsRepo = {
  due(): Promise<Flashcard[]> {
    const today = new Date().toISOString().slice(0, 10);
    return dbClient.getAllAsync<Flashcard>(
      `SELECT id,user_id,book_id,annotation_id,front,back,box,due_date,created_at,updated_at,deleted_at
       FROM flashcards WHERE deleted_at IS NULL AND due_date <= ? ORDER BY box, due_date LIMIT 50`,
      [today]
    );
  },
  async review(id: number, known: boolean) {
    const now = new Date().toISOString();
    // 乐观更新本地（间隔重复算法与后端一致）
    const card = await dbClient.getFirstAsync<Flashcard>(
      'SELECT * FROM flashcards WHERE id = ?', [id]
    );
    if (card) {
      const intervals = [1, 2, 4, 7, 14, 30, 60];
      const box = known ? Math.min(card.box + 1, intervals.length) : 1;
      const due = new Date();
      due.setDate(due.getDate() + (known ? intervals[box - 1] : 1));
      await upsertFlashcard({
        ...card, box, due_date: due.toISOString().slice(0, 10),
        updated_at: now,
      });
    }
    try {
      await services.reviewFlashcard(id, known);
    } catch {
      await enqueueMutation({
        table: 'flashcards', op: 'update',
        payload: JSON.stringify({ id, known }),
      });
    }
  },
  async createFromQuote(bookId: number | string, quote: string) {
    const now = new Date().toISOString();
    const uid = currentUserId();
    const localId = -Date.now();
    const optimistic: Flashcard = {
      id: localId, user_id: uid ?? 0, book_id: Number(bookId), annotation_id: null,
      front: quote, back: '', box: 1, due_date: now.slice(0, 10),
      created_at: now, updated_at: now, deleted_at: null,
    };
    await upsertFlashcard(optimistic);
    try {
      const res = await services.createFlashcard(bookId, quote);
      await dbClient.runAsync('DELETE FROM flashcards WHERE id = ?', [localId]);
      await upsertFlashcard({ ...optimistic, id: res.id });
      return res.id;
    } catch {
      await enqueueMutation({
        table: 'flashcards', op: 'insert',
        payload: JSON.stringify({ book_id: Number(bookId), quote, localId }),
      });
      return localId;
    }
  },
};

// ── 阅读时长 ──
export const readingLogsRepo = {
  async log(bookId: number | string, seconds: number) {
    const now = new Date().toISOString();
    const uid = currentUserId();
    const today = now.slice(0, 10);
    // 乐观累加本地（同用户同日）
    const existing = await dbClient.getFirstAsync<ReadingLog>(
      'SELECT * FROM reading_logs WHERE user_id = ? AND book_id = ? AND log_date = ? AND deleted_at IS NULL',
      [uid, Number(bookId), today]
    );
    if (existing) {
      await upsertReadingLog({ ...existing, seconds: existing.seconds + seconds, updated_at: now });
    } else {
      await upsertReadingLog({
        id: -Date.now(), user_id: uid ?? 0, book_id: Number(bookId), log_date: today,
        seconds, created_at: now, updated_at: now, deleted_at: null,
      });
    }
    try {
      await services.logReading(bookId, seconds);
    } catch {
      await enqueueMutation({
        table: 'reading_logs', op: 'insert',
        payload: JSON.stringify({ book_id: Number(bookId), seconds }),
      });
    }
  },
};

// ── 伴读消息（聊天天然在线，但本地缓存以便离线回看）──
export const companionRepo = {
  async list(): Promise<CompanionMessage[]> {
    const rows = await dbClient.getAllAsync<CompanionMessage>(
      `SELECT id,user_id,persona_id,scope,book_id,role,content,created_at,updated_at,deleted_at
       FROM companion_messages WHERE deleted_at IS NULL ORDER BY id ASC`
    );
    // 去重：同步拉取的 server 消息（正 id）与本地临时消息（负 id）内容相同时，
    // 保留 server 那份（id 更大），避免重复气泡。
    const seen = new Map<string, CompanionMessage>();
    for (const m of rows) {
      const key = `${m.role}|${m.content}|${(m.created_at || '').slice(0, 10)}`;
      const prev = seen.get(key);
      if (!prev || m.id > prev.id) seen.set(key, m);
    }
    return [...seen.values()];
  },
  async append(msg: CompanionMessage) {
    await upsertCompanionMessage({
      id: msg.id, userId: msg.user_id, personaId: msg.persona_id, scope: msg.scope,
      bookId: msg.book_id, role: msg.role, content: msg.content,
      createdAt: msg.created_at, updatedAt: msg.updated_at, deletedAt: msg.deleted_at,
    });
  },
};

// ── 统计（离线也可由本地日志算出，与后端公式近似一致）──
export const statsRepo = {
  async compute(): Promise<ReadingStats> {
    const logs = await dbClient.getAllAsync<ReadingLog>(
      `SELECT log_date, seconds FROM reading_logs WHERE deleted_at IS NULL`
    );
    const books = await dbClient.getFirstAsync<{ c: number }>(
      'SELECT COUNT(*) AS c FROM books WHERE deleted_at IS NULL'
    );

    const dates = new Set(logs.map((l) => l.log_date));
    let streak = 0;
    const cur = new Date();
    while (dates.has(cur.toISOString().slice(0, 10))) {
      streak++;
      cur.setDate(cur.getDate() - 1);
    }

    let longest = 0;
    let run = 0;
    let prev: string | null = null;
    const sorted = [...logs].sort((a, b) => (a.log_date < b.log_date ? -1 : 1));
    for (const l of sorted) {
      if (prev && dayDiff(prev, l.log_date) === 1) run++;
      else run = 1;
      longest = Math.max(longest, run);
      prev = l.log_date;
    }

    const totalSeconds = logs.reduce((s, l) => s + (l.seconds || 0), 0);

    return {
      days: logs.map((l) => ({ date: l.log_date, seconds: l.seconds })),
      streak,
      longest,
      total_seconds: totalSeconds,
      total_minutes: Math.round(totalSeconds / 60),
      total_books: books?.c ?? 0,
    };
  },
};

function dayDiff(a: string, b: string): number {
  const da = new Date(a + 'T00:00:00').getTime();
  const db = new Date(b + 'T00:00:00').getTime();
  return Math.round((db - da) / 86400000);
}
