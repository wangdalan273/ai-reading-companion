import { describe, expect, it } from 'vitest';
import {
  EPUB_READER_SELECTION_ENABLED,
  buildEpubNavigationScript,
  buildSelectionModeScript,
  shouldAcceptSelection,
} from './readerInteraction';

describe('reader interaction', () => {
  it('never accepts webview selections while the reader is in page-turn mode', () => {
    expect(shouldAcceptSelection(false, '来')).toBe(false);
    expect(shouldAcceptSelection(false, '一段较长但意外选中的文字')).toBe(false);
  });

  it('accepts a deliberate non-empty selection only in selection mode', () => {
    expect(shouldAcceptSelection(true, '  阴阳相互制约  ')).toBe(true);
    expect(shouldAcceptSelection(true, '   ')).toBe(false);
  });

  it('keeps native selection support enabled and switches intent through the EPUB theme', () => {
    const enabled = buildSelectionModeScript(true);
    const disabled = buildSelectionModeScript(false);

    expect(EPUB_READER_SELECTION_ENABLED).toBe(true);
    expect(enabled).toContain("'user-select': 'auto'");
    expect(enabled).toContain("'-webkit-user-select': 'auto'");
    expect(disabled).toContain("'user-select': 'none'");
    expect(disabled).toContain("'-webkit-touch-callout': 'none'");
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
