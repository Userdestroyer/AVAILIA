> ## Documentation Index
> Fetch the complete documentation index at: https://polza.ai/docs/llms.txt
> Use this file to discover all available pages before exploring further.

# Поиск в интернете

> Как добавить веб-поиск к любой AI модели

Polza.AI добавляет веб-поиск к любой модели — от GPT-5 до Claude и Gemini: ищет сама Яндексом или Google либо передаёт поиск провайдеру модели, если он умеет искать. Модель получает актуальные данные из интернета, а вы — ответ со ссылками на источники.

## Быстрый старт

### Chat Completions API

Подключите плагин `web` — Polza найдёт информацию по вашему сообщению и передаст результаты модели вместе с запросом:

```typescript TypeScript theme={null}
import OpenAI from 'openai';

const openai = new OpenAI({
  baseURL: 'https://polza.ai/api/v1',
  apiKey: '<POLZA_AI_API_KEY>'
});

const completion = await openai.chat.completions.create({
  model: 'openai/gpt-5.2',
  messages: [
    { role: 'user', content: 'Последние новости о квантовых компьютерах' }
  ],
  // @ts-ignore — plugins не входит в типы OpenAI SDK
  plugins: [{ id: 'web' }]
});

console.log(completion.choices[0].message.content);
console.log(completion.choices[0].message.annotations); // ссылки на источники
```

```python Python theme={null}
from openai import OpenAI

client = OpenAI(
    base_url="https://polza.ai/api/v1",
    api_key="<POLZA_AI_API_KEY>"
)

completion = client.chat.completions.create(
    model="openai/gpt-5.2",
    messages=[
        {"role": "user", "content": "Последние новости о квантовых компьютерах"}
    ],
    extra_body={
        "plugins": [{"id": "web"}]
    }
)

print(completion.choices[0].message.content)
print(completion.choices[0].message.annotations)  # ссылки на источники
```

```bash cURL theme={null}
curl -X POST "https://polza.ai/api/v1/chat/completions" \
  -H "Authorization: Bearer <POLZA_AI_API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "openai/gpt-5.2",
    "messages": [
      {"role": "user", "content": "Последние новости о квантовых компьютерах"}
    ],
    "plugins": [{"id": "web"}]
  }'
```

### Responses API

Используйте `tools` с типом `web_search_preview` (или `web_search`). В `output` придут элемент поиска `web_search_call` и элемент `message` с ответом:

```typescript TypeScript theme={null}
const response = await fetch('https://polza.ai/api/v1/responses', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer <POLZA_AI_API_KEY>',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    model: 'openai/gpt-5.2',
    input: 'Последние новости о квантовых компьютерах',
    tools: [{ type: 'web_search_preview' }]
  })
});

const data = await response.json();
const message = data.output.find((item) => item.type === 'message');
console.log(message.content[0].text);
console.log(message.content[0].annotations); // ссылки на источники
```

```python Python theme={null}
import requests

response = requests.post(
    'https://polza.ai/api/v1/responses',
    headers={'Authorization': 'Bearer <POLZA_AI_API_KEY>'},
    json={
        'model': 'openai/gpt-5.2',
        'input': 'Последние новости о квантовых компьютерах',
        'tools': [{'type': 'web_search_preview'}]
    }
)

data = response.json()
message = next(item for item in data['output'] if item['type'] == 'message')
print(message['content'][0]['text'])
print(message['content'][0]['annotations'])  # ссылки на источники
```

```bash cURL theme={null}
curl -X POST "https://polza.ai/api/v1/responses" \
  -H "Authorization: Bearer <POLZA_AI_API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "openai/gpt-5.2",
    "input": "Последние новости о квантовых компьютерах",
    "tools": [{"type": "web_search_preview"}]
  }'
```

## Как работает поиск

Искать может Polza или провайдер модели — выбор за вами.

* **Поиск Polza** — поисковиком Яндекса (по умолчанию) или Google. Работает с любой моделью, в том числе без поддержки вызова функций, и в любом формате API. Polza ищет один раз до вызова модели — по тексту вашего последнего сообщения или по `search_prompt` — и добавляет найденные результаты в запрос. Результаты увеличивают `prompt_tokens`: модель читает их как часть запроса.
* **Родной поиск провайдера** — поиск выполняет сам провайдер модели (например, Anthropic, OpenAI, Google). Доступен моделям, у которых есть провайдер с возможностью `native_web_search` (см. [возможности провайдера](https://polza.ai/docs/gaidy/models.md#возможности-провайдера-capabilities)); запрос уходит только таким провайдерам. Если у модели родного поиска нет, ищет Polza — запрос не падает.

Модели Perplexity ищут в интернете всегда, на каждый запрос: для них плагин `web` не нужен.

## Как включить

| Способ | API | Кто ищет |
| - | - | - |
| `plugins: [{"id": "web"}]` (`engine`: `yandex`, `google`) | Chat Completions, Responses | Polza |
| `plugins: [{"id": "web", "engine": "native"}]` | Chat Completions, Responses | Провайдер, если умеет, иначе Polza |
| `web_search_options: {}` | Chat Completions | Провайдер, если умеет, иначе Polza |
| `tools: [{"type": "web_search"}]` (и `web_search_preview`, `web_search_preview_2025_03_11`) | Responses | Провайдер, если умеет, иначе Polza |
| `tools: [{"type": "web_search_20250305", "name": "web_search"}]` (и `web_search_20260209`) | Anthropic Messages | Провайдер, если умеет, иначе Polza |
| `tools: [{"googleSearch": {}}]` | Gemini | Провайдер, если умеет, иначе Polza |

Чтобы искала точно Polza и нужным поисковиком, используйте плагин `web` с `engine: "yandex"` или `"google"`. Поисковик, число результатов и поисковый запрос задаются только в [настройках плагина](#настройки-плагина-web).

Когда ищет провайдер, ему передаётся `search_context_size` (Chat Completions и Responses); остальные параметры родных форматов (`user_location`, `filters` и другие) принимаются для совместимости, но на поиск не влияют. Когда ищет Polza, модель, умеющая вызывать функции, сама решает, когда и что искать, и может выполнить несколько поисков за запрос; `max_uses`, `allowed_domains` и `blocked_domains` при этом учитываются. Модели без вызова функций получают один поиск до вызова, как с плагином `web`.

## Формат ответа

### Chat Completions API

Источники возвращаются в поле `annotations`:

```json theme={null}
{
  "choices": [{
    "message": {
      "role": "assistant",
      "content": "По данным ЦБ, официальный курс доллара на сегодня — ...",
      "annotations": [
        {
          "type": "url_citation",
          "url_citation": {
            "url": "https://example.com/usd-rate",
            "title": "Официальные курсы валют",
            "content": "Выдержка из страницы...",
            "start_index": 0,
            "end_index": 0
          }
        }
      ]
    }
  }]
}
```

### Поля аннотации

| Поле | Тип | Описание |
| - | - | - |
| `type` | string | Всегда `"url_citation"` |
| `url_citation.url` | string | URL источника |
| `url_citation.title` | string | Заголовок страницы |
| `url_citation.content` | string | Выдержка из страницы, которую получила модель |
| `url_citation.start_index` | number | Всегда `0`: привязки источника к месту в тексте ответа нет |
| `url_citation.end_index` | number | Всегда `0` |

`annotations` содержат все найденные источники, а не только те, на которые модель сослалась в тексте.

### Responses API

Источники — в `annotations` сообщения. С родным `web_search` в `output` есть и элемент `web_search_call` с адресами источников — перед сообщением, а в стриме после него:

```json theme={null}
{
  "output": [
    {
      "type": "web_search_call",
      "id": "ws_...",
      "status": "completed",
      "action": {
        "type": "search",
        "sources": [
          { "type": "url", "url": "https://example.com/usd-rate" }
        ]
      }
    },
    {
      "type": "message",
      "role": "assistant",
      "status": "completed",
      "content": [{
        "type": "output_text",
        "text": "По данным ЦБ, ...",
        "annotations": [
          {
            "type": "url_citation",
            "url": "https://example.com/usd-rate",
            "title": "Официальные курсы валют",
            "start_index": 0,
            "end_index": 0,
            "url_citation": { "url": "https://example.com/usd-rate", "title": "Официальные курсы валют", "content": "...", "start_index": 0, "end_index": 0 }
          }
        ]
      }]
    }
  ]
}
```

С `plugins: [{"id": "web"}]` элемента `web_search_call` нет — источники только в `annotations`.

### Anthropic Messages и Gemini

* **Anthropic Messages** — источники в `citations` текстового блока.
* **Gemini** — источники в `groundingMetadata.groundingChunks`.

## Настройки плагина `web`

| Параметр | Тип | По умолчанию | Описание |
| - | - | - | - |
| `max_results` | number | 5 | Сколько результатов добавить в запрос: от 1 до 20 (для Google — до 10) |
| `search_prompt` | string | — | Поисковый запрос. Если не задан, ищется текст вашего последнего сообщения |
| `engine` | string | `yandex` | Поисковик: `yandex` или `google`; `native` — [родной поиск провайдера](#как-работает-поиск), если модель его поддерживает, иначе поиск Яндексом; `exa` — поиск Яндексом |
| `enabled` | boolean | true | Включить/выключить плагин |

### Пример с настройками

```json theme={null}
{
  "model": "google/gemini-2.5-pro",
  "messages": [
    {"role": "user", "content": "Сравни последние релизы NVIDIA и AMD"}
  ],
  "plugins": [{
    "id": "web",
    "engine": "google",
    "max_results": 3,
    "search_prompt": "Новости NVIDIA AMD GPU"
  }]
}
```

## Usage и стоимость

Поиск Polza стоит **0,50 ₽** за выполненный поиск — и Яндексом, и Google.

Родной поиск провайдера оплачивается по его тарифу — поле `web_search_per_thousand` в `pricing` [каталога моделей](https://polza.ai/docs/gaidy/models.md) (₽ за 1000 поисков). Например, при `web_search_per_thousand: "1186.41"` один поиск стоит 1,19 ₽.

Стоимость поиска входит в `cost_rub` запроса, число поисков — в `usage.server_tool_use.web_search_requests`:

```json theme={null}
{
  "usage": {
    "prompt_tokens": 1240,
    "completion_tokens": 91,
    "total_tokens": 1331,
    "server_tool_use": {
      "web_search_requests": 1
    },
    "cost_rub": 0.53,
    "cost": 0.53
  }
}
```

В [истории генераций](https://polza.ai/docs/api-reference/history/generations.md) поиск виден строкой состава цены `costLines`: `plugin:web_search` — поиск Polza до вызова модели, `tool:polza:web_search` — поиск Polza по вызову модели, `native_web_search` — родной поиск провайдера.

Если поисковик не ответил, запрос не падает: модель отвечает без результатов поиска. Такой поиск не оплачивается и не попадает в счётчик.

## Советы

> [accordion] Не комбинируйте с json\_object
>
> Формат ответа `json_object` или `json_schema` может мешать модели включать цитаты и ссылки на источники. Если вам нужен структурированный вывод с веб-поиском, обрабатывайте текстовый ответ с аннотациями отдельно.

> [accordion] Используйте search\_prompt для точности
>
> Плагин `web` ищет текст вашего последнего сообщения. Если сообщение длинное или вопрос многосоставный, задайте точный поисковый запрос в `search_prompt`.

> [accordion] Проверяйте annotations
>
> Наличие `url_citation` в `annotations` подтверждает, что поиск был выполнен и его результаты попали к модели. Если `annotations` пуст — поиск ничего не нашёл или поисковик не ответил.

> [accordion] Комбинация с изображениями
>
> Веб-поиск можно комбинировать с отправкой изображений. Например, отправьте фото продукта и попросите найти актуальные цены — задайте название товара в `search_prompt`.

## Следующие шаги

> [card] [Плагины](https://polza.ai/docs/gaidy/plugins.md)
>
> Все доступные плагины и их настройки

> [card] [Tool Calling](https://polza.ai/docs/gaidy/tool-calling.md)
>
> Вызов функций для получения данных

> [card] [Responses API](https://polza.ai/docs/api-reference/responses/create.md)
>
> Документация Responses API

> [card] [Chat Completions](https://polza.ai/docs/gaidy/chat-completions.md)
>
> Основы работы с Chat Completions API


This documentation is built and hosted on [Mintlify](https://mintlify.com), a developer documentation platform.