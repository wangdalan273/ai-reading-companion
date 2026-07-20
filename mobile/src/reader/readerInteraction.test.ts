import { describe, expect, it } from 'vitest';
import {
  EPUB_READER_SELECTION_ENABLED,
  buildEpubNavigationScript,
  buildSelectionModeScript,
  shouldAcceptSelection,
} from './readerInteraction';

describe('reader interaction', () => {
  it('accepts non-empty long-press selections without a separate selection mode', () => {
    expect(shouldAcceptSelection(false, '来')).toBe(true);
    expect(shouldAcceptSelection(false, '一段较长但有效选中的文字')).toBe(true);
  });

  it('rejects only empty selections', () => {
    expect(shouldAcceptSelection(true, '  阴阳相互制约  ')).toBe(true);
    expect(shouldAcceptSelection(true, '   ')).toBe(false);
  });

  it('toggles selection in the current document without re-registering the EPUB theme', () => {
    const enabled = buildSelectionModeScript(true);
    const disabled = buildSelectionModeScript(false);

    expect(EPUB_READER_SELECTION_ENABLED).toBe(true);
    expect(enabled).toContain("setProperty('user-select', 'auto'");
    expect(enabled).toContain("setProperty('-webkit-user-select', 'auto'");
    expect(disabled).toContain("setProperty('user-select', 'none'");
    expect(disabled).toContain("setProperty('-webkit-touch-callout', 'none'");
    expect(enabled).not.toContain('rendition.themes.default');
    expect(disabled).not.toContain('rendition.themes.default');
  });

  it('builds an escaped navigation command with a spine-path fallback', () => {
    const script = buildEpubNavigationScript("text/医者's-note.xhtml#起点");

    expect(script).toContain('const target = "text/医者\'s-note.xhtml#起点"');
    expect(script).toContain('spineItems.find');
    expect(script).toContain('section.index');
    expect(script).toContain("type: 'readerNavigationResult'");
    expect(script).toContain("ok: true");
    expect(script).toContain("ok: false");
    expect(script).not.toContain("rendition.display('text/医者's-note.xhtml#起点')");
  });
});
