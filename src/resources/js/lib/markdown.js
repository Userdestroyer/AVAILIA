import DOMPurify from 'dompurify';
import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import go from 'highlight.js/lib/languages/go';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import plaintext from 'highlight.js/lib/languages/plaintext';
import python from 'highlight.js/lib/languages/python';
import sql from 'highlight.js/lib/languages/sql';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import yaml from 'highlight.js/lib/languages/yaml';
import { Marked } from 'marked';
import { markedHighlight } from 'marked-highlight';
import 'highlight.js/styles/github-dark.css';

// Только ходовые языки — полный highlight.js весит ~1 МБ
const LANGUAGES = { bash, css, go, javascript, json, php, plaintext, python, sql, typescript, xml, yaml };
for (const [name, language] of Object.entries(LANGUAGES)) {
    hljs.registerLanguage(name, language);
}
hljs.registerAliases(['sh', 'shell', 'zsh'], { languageName: 'bash' });
hljs.registerAliases(['js', 'jsx', 'vue'], { languageName: 'javascript' });
hljs.registerAliases(['ts', 'tsx'], { languageName: 'typescript' });
hljs.registerAliases(['html', 'svg'], { languageName: 'xml' });
hljs.registerAliases(['py'], { languageName: 'python' });
hljs.registerAliases(['yml'], { languageName: 'yaml' });

const marked = new Marked(
    { gfm: true, breaks: true },
    markedHighlight({
        emptyLangClass: 'hljs',
        langPrefix: 'hljs language-',
        highlight(code, lang) {
            const language = hljs.getLanguage(lang) ? lang : 'plaintext';
            return hljs.highlight(code, { language }).value;
        },
    }),
);

// Ссылки из ответов модели — в новой вкладке и без доступа к нашей
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer nofollow');
    }
});

// Кнопка «скопировать» у блока кода: добавляется в уже очищенный HTML (наша разметка, не из ответа);
// клик ловит MarkdownContent по data-copy-code
const ICON = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"';
const COPY_BUTTON = '<button type="button" class="code-copy" data-copy-code title="Скопировать код">'
    + `<svg class="code-copy-idle" ${ICON} stroke-width="1.5"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>`
    + `<svg class="code-copy-done" ${ICON} stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>`
    + '</button>';

/**
 * Markdown ответа модели → безопасный HTML. Санитайз обязателен: ответ может содержать
 * любой HTML (в том числе подсунутый через промпт), а мы вставляем его через v-html.
 */
export function renderMarkdown(source) {
    return DOMPurify.sanitize(marked.parse(source ?? '')).replaceAll('<pre>', `<pre>${COPY_BUTTON}`);
}
