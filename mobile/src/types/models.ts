// 与后端模型一一对应的 TS 类型。后端迁移已用标准 Schema，
// 这些字段与 database/migrations 保持一致，新增字段时同步更新此处。

export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at?: string | null;
  created_at: string;
  updated_at: string;
}

export type BookFormat = 'epub' | 'pdf';

export interface Book {
  id: number;
  user_id: number;
  title: string;
  author?: string | null;
  format: BookFormat;
  size?: number | null;
  cover_path?: string | null;
  /** 后端返回时注入的绝对封面 URL */
  cover_url?: string | null;
  cover_gradient?: string | null;
  /** 对象存储签名 URL 由后端在返回时注入，本地不存二进制 */
  file_url?: string | null;
  mindmap_md?: string | null;
  concept_graph_status?: string | null;
  character_graph_status?: string | null;
  argument_map_status?: string | null;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface Annotation {
  id: number;
  book_id: number;
  user_id: number;
  loc: string; // EPUB CFI 或 PDF 定位
  quote?: string | null;
  tag?: string | null;
  note?: string | null;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface Flashcard {
  id: number;
  user_id: number;
  book_id: number;
  annotation_id?: number | null;
  front: string;
  back: string;
  box: number; // 记忆盒 1..5
  due_date: string;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface ReadingLog {
  id: number;
  user_id: number;
  book_id: number;
  log_date: string; // YYYY-MM-DD
  seconds: number;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface CompanionMessage {
  id: number;
  user_id: number;
  persona_id?: number | null;
  scope: 'all' | 'vault' | 'book';
  book_id?: number | null;
  role: 'user' | 'assistant';
  content: string;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface Persona {
  id: number;
  user_id: number;
  name: string;
  emoji?: string | null;
  description?: string | null;
  system_prompt: string;
  is_default: boolean;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

/** GET /v1/reading/stats 返回结构 */
export interface ReadingStats {
  days: { date: string; seconds: number }[];
  streak: number;
  longest: number;
  total_seconds: number;
  total_minutes: number;
  total_books: number;
}

/** /api/sync 返回结构：以 updated_at 为游标增量下发 */
export interface SyncPullResponse {
  server_time: string;
  books: Book[];
  annotations: Annotation[];
  flashcards: Flashcard[];
  reading_logs: ReadingLog[];
  companion_messages: CompanionMessage[];
  personas: Persona[];
}
