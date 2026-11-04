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

/**
 * Markdown ответа модели → безопасный HTML. Санитайз обязателен: ответ может содержать
 * любой HTML (в том числе подсунутый через промпт), а мы вставляем его через v-html.
 */
export function renderMarkdown(source) {
    return DOMPurify.sanitize(marked.parse(source ?? ''));
}
