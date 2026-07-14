export type Book = {
  id: number;
  title: string;
  author?: string | null;
  format: 'epub' | 'pdf';
  size?: number;
  cover_url?: string | null;
  updated_at?: string;
};

export type User = { id: number; name: string; email: string };

export type Persona = {
  id: number;
  name: string;
  description?: string | null;
  system_prompt: string;
  is_default: boolean;
};

export type ChatMessage = {
  role: 'user' | 'assistant';
  content: string;
  scope?: 'book' | 'vault' | 'all';
  persona_id?: number | null;
};

export type Flashcard = {
  id: number;
  front: string;
  back: string;
  box: number;
  book_id: number;
  book?: { id: number; title: string };
};

export type ReadingStats = {
  days: { date: string; seconds: number }[];
  streak: number;
  longest: number;
  total_seconds: number;
  total_minutes: number;
  total_books: number;
};

export type Annotation = {
  id: number;
  book_id: number;
  loc: string;
  quote: string;
  tag?: string | null;
  note?: string | null;
};

export type { AiProviderPreset, AiSettingsDraft, AiSettingsPayload } from './settings/aiSettings';
