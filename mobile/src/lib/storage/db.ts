import * as SQLite from 'expo-sqlite';
import { drizzle } from 'drizzle-orm/expo-sqlite';
import { sqliteTable, text, integer, index } from 'drizzle-orm/sqlite-core';

/**
 * 本地数据库（设备上的真相源）。
 * 与后端表结构对齐；云端是账号真相源，二者通过同步引擎对齐。
 * 选用 expo-sqlite + drizzle-orm：类型安全、适合增量 upsert。
 *
 * 注意：所有「读」走本文件提供的 upsert/get* 函数（带全字段列名），
 * 不要假设 drizzle 的 $inferInsert 列集合与建表语句一致——以建表语句为准。
 */
export const dbClient = SQLite.openDatabaseSync('aireading.db');
export const db = drizzle(dbClient);

// ---- 表结构（与后端迁移保持一致，含同步所需全部列）----
export const books = sqliteTable(
  'books',
  {
    id: integer('id').primaryKey(),
    userId: integer('user_id'),
    title: text('title'),
    author: text('author'),
    format: text('format'),
    size: integer('size'),
    coverPath: text('cover_path'),
    coverUrl: text('cover_url'),
    coverGradient: text('cover_gradient'),
    fileUrl: text('file_url'),
    mindmapMd: text('mindmap_md'),
    conceptGraphStatus: text('concept_graph_status'),
    characterGraphStatus: text('character_graph_status'),
    argumentMapStatus: text('argument_map_status'),
    createdAt: text('created_at'),
    updatedAt: text('updated_at'),
    deletedAt: text('deleted_at'),
  },
  (t) => ({ userIdx: index('books_user').on(t.userId) })
);

export const annotations = sqliteTable('annotations', {
  id: integer('id').primaryKey(),
  bookId: integer('book_id'),
  userId: integer('user_id'),
  loc: text('loc'),
  quote: text('quote'),
  tag: text('tag'),
  note: text('note'),
  createdAt: text('created_at'),
  updatedAt: text('updated_at'),
  deletedAt: text('deleted_at'),
});

export const flashcards = sqliteTable('flashcards', {
  id: integer('id').primaryKey(),
  userId: integer('user_id'),
  bookId: integer('book_id'),
  annotationId: integer('annotation_id'),
  front: text('front'),
  back: text('back'),
  box: integer('box'),
  dueDate: text('due_date'),
  createdAt: text('created_at'),
  updatedAt: text('updated_at'),
  deletedAt: text('deleted_at'),
});

export const readingLogs = sqliteTable('reading_logs', {
  id: integer('id').primaryKey(),
  userId: integer('user_id'),
  bookId: integer('book_id'),
  logDate: text('log_date'),
  seconds: integer('seconds'),
  createdAt: text('created_at'),
  updatedAt: text('updated_at'),
  deletedAt: text('deleted_at'),
});

export const companionMessages = sqliteTable('companion_messages', {
  id: integer('id').primaryKey(),
  userId: integer('user_id'),
  personaId: integer('persona_id'),
  scope: text('scope'),
  bookId: integer('book_id'),
  role: text('role'),
  content: text('content'),
  createdAt: text('created_at'),
  updatedAt: text('updated_at'),
  deletedAt: text('deleted_at'),
});

export const personas = sqliteTable('personas', {
  id: integer('id').primaryKey(),
  userId: integer('user_id'),
  name: text('name'),
  emoji: text('emoji'),
  description: text('description'),
  systemPrompt: text('system_prompt'),
  isDefault: integer('is_default'),
  createdAt: text('created_at'),
  updatedAt: text('updated_at'),
  deletedAt: text('deleted_at'),
});

// 同步元数据：记录上次拉取游标等
export const syncMeta = sqliteTable('sync_meta', {
  key: text('key').primaryKey(),
  value: text('value'),
});

/** 建表（幂等），应用启动时调用一次 */
export async function migrateLocalDb() {
  await dbClient.execAsync(`
    PRAGMA journal_mode = WAL;
    CREATE TABLE IF NOT EXISTS books (
      id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, author TEXT,
      format TEXT, size INTEGER, cover_path TEXT, cover_url TEXT, cover_gradient TEXT,
      file_url TEXT, mindmap_md TEXT,
      concept_graph_status TEXT, character_graph_status TEXT, argument_map_status TEXT,
      created_at TEXT, updated_at TEXT, deleted_at TEXT
    );
    CREATE TABLE IF NOT EXISTS annotations (
      id INTEGER PRIMARY KEY, book_id INTEGER, user_id INTEGER, loc TEXT,
      quote TEXT, tag TEXT, note TEXT,
      created_at TEXT, updated_at TEXT, deleted_at TEXT
    );
    CREATE TABLE IF NOT EXISTS flashcards (
      id INTEGER PRIMARY KEY, user_id INTEGER, book_id INTEGER, annotation_id INTEGER,
      front TEXT, back TEXT, box INTEGER, due_date TEXT,
      created_at TEXT, updated_at TEXT, deleted_at TEXT
    );
    CREATE TABLE IF NOT EXISTS reading_logs (
      id INTEGER PRIMARY KEY, user_id INTEGER, book_id INTEGER, log_date TEXT,
      seconds INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT
    );
    CREATE TABLE IF NOT EXISTS companion_messages (
      id INTEGER PRIMARY KEY, user_id INTEGER, persona_id INTEGER, scope TEXT,
      book_id INTEGER, role TEXT, content TEXT,
      created_at TEXT, updated_at TEXT, deleted_at TEXT
    );
    CREATE TABLE IF NOT EXISTS personas (
      id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, emoji TEXT, description TEXT,
      system_prompt TEXT, is_default INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT
    );
    CREATE TABLE IF NOT EXISTS sync_meta (
      key TEXT PRIMARY KEY, value TEXT
    );
    CREATE TABLE IF NOT EXISTS mutation_queue (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      table_name TEXT,
      op TEXT,
      payload TEXT,
      created_at TEXT
    );
  `);
}

// ── 通用 upsert（按 id；LWW 由同步引擎在调用前比较 updatedAt）──
// 各表列名与上面建表语句严格一致。

export async function upsertBook(b: Partial<BookRow>) {
  await dbClient.runAsync(
    `INSERT INTO books (id,user_id,title,author,format,size,cover_path,cover_url,cover_gradient,
      file_url,mindmap_md,concept_graph_status,character_graph_status,argument_map_status,
      created_at,updated_at,deleted_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(id) DO UPDATE SET
       user_id=excluded.user_id, title=excluded.title, author=excluded.author, format=excluded.format,
       size=excluded.size, cover_path=excluded.cover_path, cover_url=excluded.cover_url,
       cover_gradient=excluded.cover_gradient, file_url=excluded.file_url, mindmap_md=excluded.mindmap_md,
       concept_graph_status=excluded.concept_graph_status, character_graph_status=excluded.character_graph_status,
       argument_map_status=excluded.argument_map_status, created_at=excluded.created_at,
       updated_at=excluded.updated_at, deleted_at=excluded.deleted_at`,
    [
      b.id, b.user_id, b.title, b.author, b.format, b.size, b.cover_path, b.cover_url,
      b.cover_gradient, b.file_url, b.mindmap_md, b.concept_graph_status,
      b.character_graph_status, b.argument_map_status, b.created_at, b.updated_at, b.deleted_at,
    ]
  );
}

export async function upsertAnnotation(a: Partial<AnnotationRow>) {
  await dbClient.runAsync(
    `INSERT INTO annotations (id,book_id,user_id,loc,quote,tag,note,created_at,updated_at,deleted_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(id) DO UPDATE SET
       book_id=excluded.book_id, user_id=excluded.user_id, loc=excluded.loc, quote=excluded.quote,
       tag=excluded.tag, note=excluded.note, created_at=excluded.created_at,
       updated_at=excluded.updated_at, deleted_at=excluded.deleted_at`,
    [a.id, a.book_id, a.user_id, a.loc, a.quote, a.tag, a.note, a.created_at, a.updated_at, a.deleted_at]
  );
}

export async function upsertFlashcard(f: Partial<FlashcardRow>) {
  await dbClient.runAsync(
    `INSERT INTO flashcards (id,user_id,book_id,annotation_id,front,back,box,due_date,created_at,updated_at,deleted_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(id) DO UPDATE SET
       user_id=excluded.user_id, book_id=excluded.book_id, annotation_id=excluded.annotation_id,
       front=excluded.front, back=excluded.back, box=excluded.box, due_date=excluded.due_date,
       created_at=excluded.created_at, updated_at=excluded.updated_at, deleted_at=excluded.deleted_at`,
    [f.id, f.user_id, f.book_id, f.annotation_id, f.front, f.back, f.box, f.due_date, f.created_at, f.updated_at, f.deleted_at]
  );
}

export async function upsertReadingLog(r: Partial<ReadingLogRow>) {
  await dbClient.runAsync(
    `INSERT INTO reading_logs (id,user_id,book_id,log_date,seconds,created_at,updated_at,deleted_at)
     VALUES (?,?,?,?,?,?,?,?)
     ON CONFLICT(id) DO UPDATE SET
       user_id=excluded.user_id, book_id=excluded.book_id, log_date=excluded.log_date, seconds=excluded.seconds,
       created_at=excluded.created_at, updated_at=excluded.updated_at, deleted_at=excluded.deleted_at`,
    [r.id, r.user_id, r.book_id, r.log_date, r.seconds, r.created_at, r.updated_at, r.deleted_at]
  );
}

export async function upsertCompanionMessage(m: Partial<CompanionMessageRow>) {
  await dbClient.runAsync(
    `INSERT INTO companion_messages (id,user_id,persona_id,scope,book_id,role,content,created_at,updated_at,deleted_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(id) DO UPDATE SET
       user_id=excluded.user_id, persona_id=excluded.persona_id, scope=excluded.scope, book_id=excluded.book_id,
       role=excluded.role, content=excluded.content, created_at=excluded.created_at,
       updated_at=excluded.updated_at, deleted_at=excluded.deleted_at`,
    [m.id, m.user_id, m.persona_id, m.scope, m.book_id, m.role, m.content, m.created_at, m.updated_at, m.deleted_at]
  );
}

export async function upsertPersona(p: Partial<PersonaRow>) {
  await dbClient.runAsync(
    `INSERT INTO personas (id,user_id,name,emoji,description,system_prompt,is_default,created_at,updated_at,deleted_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(id) DO UPDATE SET
       user_id=excluded.user_id, name=excluded.name, emoji=excluded.emoji, description=excluded.description,
       system_prompt=excluded.system_prompt, is_default=excluded.is_default, created_at=excluded.created_at,
       updated_at=excluded.updated_at, deleted_at=excluded.deleted_at`,
    [p.id, p.user_id, p.name, p.emoji, p.description, p.system_prompt, p.is_default, p.created_at, p.updated_at, p.deleted_at]
  );
}

export async function getMeta(key: string): Promise<string | null> {
  const row = await dbClient.getFirstAsync<{ value: string }>(
    'SELECT value FROM sync_meta WHERE key = ?',
    [key]
  );
  return row?.value ?? null;
}

export async function setMeta(key: string, value: string) {
  await dbClient.runAsync(
    `INSERT INTO sync_meta (key, value) VALUES (?, ?)
     ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
    [key, value]
  );
}

// ── 行类型（与上面 upsert 入参对应，便于复用）──
export interface BookRow {
  id?: number; user_id?: number; title?: string; author?: string; format?: string;
  size?: number; cover_path?: string; cover_url?: string; cover_gradient?: string;
  file_url?: string; mindmap_md?: string; concept_graph_status?: string;
  character_graph_status?: string; argument_map_status?: string;
  created_at?: string; updated_at?: string; deleted_at?: string;
}
export interface AnnotationRow {
  id?: number; book_id?: number; user_id?: number; loc?: string; quote?: string;
  tag?: string; note?: string; created_at?: string; updated_at?: string; deleted_at?: string;
}
export interface FlashcardRow {
  id?: number; user_id?: number; book_id?: number; annotation_id?: number; front?: string;
  back?: string; box?: number; due_date?: string; created_at?: string; updated_at?: string; deleted_at?: string;
}
export interface ReadingLogRow {
  id?: number; user_id?: number; book_id?: number; log_date?: string; seconds?: number;
  created_at?: string; updated_at?: string; deleted_at?: string;
}
export interface CompanionMessageRow {
  id?: number; user_id?: number; persona_id?: number; scope?: string; book_id?: number;
  role?: string; content?: string; created_at?: string; updated_at?: string; deleted_at?: string;
}
export interface PersonaRow {
  id?: number; user_id?: number; name?: string; emoji?: string; description?: string;
  system_prompt?: string; is_default?: number; created_at?: string; updated_at?: string; deleted_at?: string;
}
