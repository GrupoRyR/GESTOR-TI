import MarkdownIt from 'markdown-it';

/**
 * Safe Markdown rendering for documentation, comments and requirements.
 * Raw HTML is never rendered (`html: false` escapes it); markdown-it's
 * `validateLink` refuses `javascript:`, `vbscript:`, `file:` and `data:`
 * URLs (also when obfuscated with entities or case), so those stay as plain
 * text. Every link opens in a new tab with `rel="noopener noreferrer"`.
 */
const md = new MarkdownIt({ html: false, linkify: true, typographer: false, breaks: false });

md.renderer.rules.link_open = (tokens, index, options, _env, self) => {
    const token = tokens[index]!;
    token.attrSet('target', '_blank');
    token.attrSet('rel', 'noopener noreferrer');
    return self.renderToken(tokens, index, options);
};

/** Markdown → sanitized HTML string, ready for `dangerouslySetInnerHTML`. */
export function renderMarkdown(source: string): string {
    if (source.trim() === '') return '';
    return md.render(source);
}
