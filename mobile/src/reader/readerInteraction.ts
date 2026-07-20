export const EPUB_READER_SELECTION_ENABLED = true;

export function shouldAcceptSelection(selectionMode: boolean, quote: string): boolean {
  return quote.trim().length > 0;
}

export function buildSelectionModeScript(enabled: boolean): string {
  const selection = enabled ? 'auto' : 'none';
  const callout = enabled ? 'default' : 'none';
  // Re-registering the default rendition theme here would force some EPUBs to
  // lay out again and move the reader back to the beginning of the chapter.
  return `
    (() => {
      window.__readerSelectionEnabled = ${enabled};
      rendition.getContents().forEach((content) => {
        const doc = content.document;
        if (!doc) return;
        [doc.documentElement, doc.body].filter(Boolean).forEach((node) => {
          node.style.setProperty('-webkit-touch-callout', '${callout}', 'important');
          node.style.setProperty('-webkit-user-select', '${selection}', 'important');
          node.style.setProperty('user-select', '${selection}', 'important');
        });
        if (!window.__readerSelectionEnabled) doc.getSelection()?.removeAllRanges();
      });
    })();
    true;
  `;
}

export function buildEpubNavigationScript(targetHref: string): string {
  return `
    (async () => {
      const target = ${JSON.stringify(targetHref)};
      const postResult = (payload) => {
        const bridge = window.ReactNativeWebView || window;
        bridge.postMessage(JSON.stringify({ type: 'readerNavigationResult', target, ...payload }));
      };
      try {
        const hashIndex = target.indexOf('#');
        const targetPath = (hashIndex >= 0 ? target.slice(0, hashIndex) : target).replace(/^\\.\\//, '');
        const fragment = hashIndex >= 0 ? target.slice(hashIndex) : '';
        const spineItems = (book.spine && book.spine.spineItems) || [];
        const normalized = (value) => String(value || '').replace(/^\\.\\//, '').replace(/^\\//, '');
        const direct = book.spine && book.spine.get ? book.spine.get(targetPath) : undefined;
        const section = direct || spineItems.find((item) => {
          const href = normalized(item.href);
          const wanted = normalized(targetPath);
          return href === wanted || href.endsWith('/' + wanted) || wanted.endsWith('/' + href);
        });
        let destination = target;
        if (section) {
          destination = fragment
            ? String(section.href) + fragment
            : (typeof section.index === 'number' ? section.index : section.href);
        } else if (!target.startsWith('epubcfi(')) {
          postResult({ ok: false, message: '目录目标不在书籍正文中' });
          return;
        }
        await rendition.display(destination);
        postResult({ ok: true, sectionIndex: section && section.index });
      } catch (error) {
        postResult({ ok: false, message: error && error.message ? error.message : '章节跳转失败' });
      }
    })();
    true;
  `;
}
