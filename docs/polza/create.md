> ## Documentation Index
> Fetch the complete documentation index at: https://polza.ai/docs/llms.txt
> Use this file to discover all available pages before exploring further.

# POST Responses

> Responses API — OpenAI-совместимый формат запросов с tool calling и streaming

## О Responses API

> [!WARNING]
>
> Responses API находится в **бета-версии**. API может измениться без предварительного уведомления.

Responses API — формат, совместимый с OpenAI Responses API. Работает с официальным OpenAI SDK
(`client.responses.create(...)`), Codex CLI и другими каноничными клиентами. Внутри запрос
трансформируется в Chat Completions и уходит выбранному провайдеру.

Эндпоинты:

| Метод | Путь | Назначение |
| - | - | - |
| `POST` | `/v1/responses` | Создание ответа (обычный и streaming) |
| `POST` | `/v1/responses/compact` | Компактизация истории диалога |

Пути `/v2/responses` и `/v2/responses/compact` — алиасы, поведение идентично.

> [!NOTE]
>
> **API stateless.** Мы не храним предыдущие ответы: `previous_response_id`, `conversation` и `store`
> не поддерживаются. Всю историю диалога — включая вызовы инструментов — передавайте в `input`
> каждым запросом. Методов `GET /v1/responses/{id}`, `DELETE` и `/cancel` нет.

## Основные параметры

| Параметр | Тип | Описание |
| - | - | - |
| `model` | string | ID модели. Поддерживает alias-формат `<model>@<key>=<value>` (см. ниже) |
| `input` | string / array | Текст или массив input items |
| `instructions` | string | Системные инструкции (эквивалент system-сообщения) |
| `stream` | boolean | Потоковая передача через SSE |

### Alias-формат модели

К имени модели можно дописать параметры маршрутизации через `@`:

```text theme={null}
openai/gpt-4o@provider=azure&reasoning_effort=high&allow_fallbacks=false
```

Поддерживаемые ключи: `provider`, `reasoning_effort`, `allow_fallbacks`. При конфликте alias
и одноимённого поля в теле запроса API вернёт 400.

### Дополнительные параметры

| Параметр | Тип | Описание |
| - | - | - |
| `tools` | array | Определения инструментов (см. раздел Tool calling) |
| `tool_choice` | string / object | `none`, `auto`, `required` или `{"type":"function","name":"..."}` |
| `parallel_tool_calls` | boolean | Разрешить параллельные вызовы инструментов |
| `max_tool_calls` | integer (1–30) | Ограничение на количество вызовов; с серверными инструментами — сколько их вызовов модель может сделать за запрос (по умолчанию 10) |
| `stop_server_tools_when` | array | Условия остановки цикла серверных инструментов: `step_count`, `spend_cap` ([подробнее](https://polza.ai/docs/gaidy/server-tools.md#лимиты-вызовов)) |
| `stream_options` | object | `hide_intermediate_text: true` — в стриме только финальный ответ, без текста промежуточных шагов серверных инструментов |
| `reasoning` | object | Настройки reasoning: `effort` (`max`/`xhigh`/`high`/`medium`/`low`/`minimal`/`none`), `summary`, `max_tokens`, `type: adaptive` для Claude 4.6+ |
| `text` | object | Формат вывода: `{"format": {"type": "text" \| "json_object" \| "json_schema", ...}}` |
| `max_output_tokens` | integer | Максимум выходных токенов |
| `temperature` | float (0–2) | Температура генерации |
| `top_p` | float (0–1) | Nucleus sampling |
| `top_k` | integer | Top-K sampling |
| `top_logprobs` | integer (0–20) | Количество log probabilities |
| `frequency_penalty` / `presence_penalty` | float (−2…2) | Штрафы за повторы |
| `include` | array | Дополнительные поля вывода, например `["reasoning.encrypted_content"]` |
| `prompt_cache_key` | string | Ключ кеширования промпта |
| `prompt_cache_retention` | string | `in_memory` или `24h` |
| `modalities` | array | `["text"]`, `["text","image"]` — для моделей с генерацией изображений |
| `image_config` | object | Параметры генерации изображений |
| `provider` | object | Предпочтения по провайдерам (см. [Выбор провайдера](https://polza.ai/docs/gaidy/provider-selection.md)) |
| `plugins` | array | Серверные плагины: `web`, `file-parser`, `moderation`, `masker`, `response-healing` |
| `metadata` | object | Произвольные key-value данные |
| `user` / `safety_identifier` | string | Идентификатор конечного пользователя |

### Не поддерживаются

Эти поля принимаются ради совместимости с OpenAI SDK, но не влияют на результат:

| Параметр | Что использовать вместо |
| - | - |
| `previous_response_id` | Передавайте всю историю в `input` |
| `conversation` | То же |
| `store` | Ответы не сохраняются, истории через API нет |
| `background` | Асинхронного режима у Responses API нет |
| `prompt` (reusable prompt templates) | Передавайте текст инструкций в `instructions` |
| `models` (массив fallback) | Alias `model@allow_fallbacks=true` |
| `session_id` | `metadata` или `user` |

## Примеры

```bash cURL theme={null}
curl -X POST "https://polza.ai/api/v1/responses" \
  -H "Authorization: Bearer <POLZA_AI_API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "openai/gpt-4o",
    "input": "Расскажи о квантовых компьютерах"
  }'
```

```python Python theme={null}
from openai import OpenAI

client = OpenAI(
    base_url="https://polza.ai/api/v1",
    api_key="<POLZA_AI_API_KEY>",
)

response = client.responses.create(
    model="openai/gpt-4o",
    input=[{"role": "user", "content": "Привет!"}],
    instructions="Отвечай кратко и по делу",
)

print(response.output_text)
```

```typescript TypeScript theme={null}
import OpenAI from 'openai';

const client = new OpenAI({
  baseURL: 'https://polza.ai/api/v1',
  apiKey: '<POLZA_AI_API_KEY>',
});

const response = await client.responses.create({
  model: 'openai/gpt-4o',
  input: [{ role: 'user', content: 'Привет!' }],
  instructions: 'Отвечай кратко и по делу',
});

console.log(response.output_text);
```

### Структура ответа

```json theme={null}
{
  "id": "gen_581761234567890123",
  "object": "response",
  "created_at": 1785312000,
  "completed_at": 1785312003,
  "model": "openai/gpt-4o",
  "status": "completed",
  "error": null,
  "output_text": "Квантовые компьютеры — это...",
  "output": [
    {
      "type": "message",
      "id": "msg_1785312000_0",
      "status": "completed",
      "role": "assistant",
      "content": [{ "type": "output_text", "text": "Квантовые компьютеры — это...", "annotations": [] }]
    }
  ],
  "incomplete_details": null,
  "usage": {
    "input_tokens": 12,
    "output_tokens": 148,
    "total_tokens": 160,
    "cost_rub": 0.0413
  }
}
```

`status` — `completed`, `incomplete` или `failed`. При `incomplete` заполняется
`incomplete_details.reason` (`max_output_tokens` или `content_filter`).

В `usage` поля продублированы в двух неймингах: `input_tokens`/`output_tokens` (Responses API)
и `prompt_tokens`/`completion_tokens` (Chat Completions) — используйте любой. `cost_rub` —
фактическая стоимость запроса, списанная с баланса.

## Tool calling

Поддерживается полный цикл: передача `tools` → получение `function_call` → отправка результата
через `function_call_output` → финальный ответ модели.

### Типы инструментов

| `type` | Поддержка |
| - | - |
| `function` | Оба формата: плоский Responses (`{"type":"function","name":...}`) и вложенный (`{"type":"function","function":{...}}`) |
| `custom` | Free-form инструмент. Внутри приводится к функции с единственным строковым полем `input`, в ответе приходит как `function_call` с `arguments = {"input": "..."}` |
| `polza:web_search`, `polza:web_fetch` и другие `polza:*` | [Серверные инструменты](https://polza.ai/docs/gaidy/server-tools.md): Polza выполняет их сама внутри запроса, вызовы видны элементами `output` |
| `web_search`, `web_search_preview` | [Веб-поиск](https://polza.ai/docs/gaidy/web-search.md): родной поиск провайдера, если модель его поддерживает, иначе поиск Polza; вызов виден элементом `web_search_call` |
| Прочие (`code_interpreter`, `file_search`, `local_shell`) | Не поддерживаются — модель их не увидит |

> [!WARNING]
>
> Возможность вызывать инструменты зависит от модели. Если модель не поддерживает tool calling,
> инструменты будут проигнорированы провайдером. Проверяйте поддержку в
> [каталоге моделей](https://polza.ai/models).

### 1. Запрос с инструментами

```json theme={null}
{
  "model": "openai/gpt-4o",
  "input": [{ "role": "user", "content": "Какая погода в Москве?" }],
  "tools": [
    {
      "type": "function",
      "name": "get_weather",
      "description": "Получить текущую погоду в городе",
      "parameters": {
        "type": "object",
        "properties": { "location": { "type": "string", "description": "Название города" } },
        "required": ["location"]
      }
    }
  ],
  "tool_choice": "auto"
}
```

### 2. Ответ с вызовом инструмента

```json theme={null}
{
  "id": "gen_581761234567890123",
  "object": "response",
  "status": "completed",
  "output_text": "",
  "output": [
    {
      "type": "function_call",
      "id": "fc_call_abc123",
      "call_id": "call_abc123",
      "name": "get_weather",
      "arguments": "{\"location\":\"Москва\"}",
      "status": "completed"
    }
  ]
}
```

### 3. Возврат результата инструмента

Выполните функцию на своей стороне и отправьте новый запрос со всей историей: исходное сообщение
пользователя, item `function_call` и item `function_call_output` с тем же `call_id`.

```json theme={null}
{
  "model": "openai/gpt-4o",
  "input": [
    { "role": "user", "content": "Какая погода в Москве?" },
    {
      "type": "function_call",
      "call_id": "call_abc123",
      "name": "get_weather",
      "arguments": "{\"location\":\"Москва\"}"
    },
    {
      "type": "function_call_output",
      "call_id": "call_abc123",
      "output": "{\"temp_c\": -3, \"condition\": \"снег\"}"
    }
  ],
  "tools": []
}
```

В поле `tools` второго запроса передайте те же определения инструментов, что и в первом.

> [!WARNING]
>
> **Item `function_call` обязателен.** Если прислать только `function_call_output` — например,
> рассчитывая, что историю подтянет `previous_response_id`, — модель получит результат инструмента
> без самого вызова и вернёт ошибку вида
> `messages with role 'tool' must be a response to a preceding message with 'tool_calls'`.
> API stateless, историю нужно передавать полностью.

### Типы input items

| `type` | Описание |
| - | - |
| `message` (или объект с `role`) | Сообщение с ролью `user`, `assistant`, `system` или `developer` |
| `function_call` | Эхо ранее полученного вызова: `call_id`, `name`, `arguments` (JSON-строка) |
| `function_call_output` | Результат вызова: `call_id` и `output` (строка или произвольный JSON) |
| `reasoning` | Reasoning-блок предыдущего хода: `content`, `summary`, `encrypted_content` |

### Мультимодальный input

В `content` сообщения можно передать массив частей:

| `type` | Поле с данными |
| - | - |
| `input_text` | `text` |
| `input_image` | `image_url` (URL или data-URI), опционально `detail` |
| `input_audio` | `input_audio: { data, format }` либо плоские `audio` и `format` |
| `input_file` | `file_url`, `file_data` (base64) или `file_id`, плюс `filename` |
| `input_video` | `video_url` |

```json theme={null}
{
  "role": "user",
  "content": [
    { "type": "input_text", "text": "Что изображено на картинке?" },
    { "type": "input_image", "image_url": "https://example.com/photo.jpg", "detail": "auto" }
  ]
}
```

## Streaming

При `stream: true` ответ приходит в формате SSE. Имена и порядок событий соответствуют
спецификации OpenAI Responses API, поэтому работают официальный OpenAI SDK и Codex CLI.

### События

| Событие | Когда приходит |
| - | - |
| `response.created` | Ответ создан (первое событие) |
| `response.output_item.added` | Начался новый output item (`message`, `reasoning` или `function_call`) |
| `response.content_part.added` | Начата текстовая часть сообщения |
| `response.output_text.delta` | Инкремент текста |
| `response.output_text.done` | Полный текст части |
| `response.content_part.done` | Часть завершена, здесь же `annotations` |
| `response.output_item.done` | Output item завершён |
| `response.function_call_arguments.delta` | Инкремент JSON-аргументов вызова инструмента |
| `response.function_call_arguments.done` | Полные аргументы вызова |
| `response.reasoning_text.delta` / `.done` | Reasoning-текст модели |
| `response.reasoning_summary_part.added` | Начало summary reasoning |
| `response.reasoning_summary_text.delta` / `.done` | Summary reasoning |
| `response.completed` | Ответ завершён, содержит полный `output` и `usage` |
| `response.incomplete` | Ответ оборван по лимиту токенов или фильтру контента |
| `response.failed` | Ошибка в процессе генерации |

Типовой порядок событий для текстового ответа:

```text theme={null}
response.created
response.output_item.added        (item.type = "message")
response.content_part.added
response.output_text.delta        × N
response.output_text.done
response.content_part.done
response.output_item.done
response.completed
data: [DONE]
```

Для вызова инструмента:

```text theme={null}
response.created
response.output_item.added                (item.type = "function_call")
response.function_call_arguments.delta     × N
response.function_call_arguments.done
response.output_item.done                 (item.type = "function_call")
response.completed
data: [DONE]
```

Источник истины для запуска инструмента — событие `response.output_item.done` с
`item.type = "function_call"`: в нём полные `name`, `arguments` и `call_id`.

### Особенности

* Каждое событие несёт монотонный `sequence_number` — используйте его для упорядочивания.
* Стрим завершается строкой `data: [DONE]`. Она нужна ChatCompletion-подобным клиентам,
  каноничные клиенты Responses API её игнорируют.
* При долгом ожидании ответа провайдера сервер шлёт SSE-комментарии `: processing` — игнорируйте
  строки, начинающиеся с `:`.
* Если модель вызывает несколько инструментов параллельно, у каждого свой `output_index`.
* При ошибке посреди стрима приходит терминальное `response.failed`, стрим не обрывается молча.

```bash cURL theme={null}
curl -N -X POST "https://polza.ai/api/v1/responses" \
  -H "Authorization: Bearer <POLZA_AI_API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "openai/gpt-4o",
    "input": "Расскажи о квантовых компьютерах",
    "stream": true
  }'
```

## Компактизация истории

`POST /v1/responses/compact` укорачивает историю диалога, когда она перестаёт помещаться в
контекст. Полезно агентским клиентам с длинными сессиями.

Сервер сохраняет все инструкции (`system` и `developer`), последние `keep_last` сообщений и все
структурные items (`function_call`, `function_call_output`, `reasoning`), а вытесненные текстовые
сообщения сворачивает в один summary-плейсхолдер. Обращения к модели не происходит — метод
бесплатный и синхронный.

```json theme={null}
{
  "model": "openai/gpt-4o",
  "input": [],
  "keep_last": 6
}
```

В `input` передайте полную историю input items. Ответ содержит укороченную историю, которую нужно
передать в следующий `POST /v1/responses`:

```json theme={null}
{
  "object": "response.compact",
  "model": "openai/gpt-4o",
  "input": []
}
```

Значение `keep_last` по умолчанию — 6.

## Следующие шаги

> [card] [Вызов функций](https://polza.ai/docs/gaidy/tool-calling.md)
>
> Tool calling в формате Chat Completions

> [card] [Codex CLI](https://polza.ai/docs/integracii/codex.md)
>
> Подключение Codex CLI к Polza.ai

> [card] [Структурный вывод](https://polza.ai/docs/gaidy/structured-output.md)
>
> JSON Schema и строгие форматы ответа

> [card] [Выбор провайдера](https://polza.ai/docs/gaidy/provider-selection.md)
>
> Маршрутизация и fallback


## OpenAPI

````yaml POST /v1/responses
openapi: 3.0.0
info:
  title: Polza.ai API
  description: AI агрегатор — унифицированный доступ к сотням AI моделей
  version: '1.0'
  contact: {}
servers:
  - url: https://polza.ai/api
    description: Production
security: []
tags: []
paths:
  /v1/responses:
    post:
      tags:
        - Responses API
      summary: Создать response (Responses API)
      operationId: ResponsesController_createResponse[1]
      parameters: []
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ResponseRequestDto'
      responses:
        '200':
          description: ''
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ResponsePresenter'
        '400':
          description: Некорректный запрос. Проверьте параметры и тело
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '401':
          description: Ошибка авторизации. Проверьте ключ доступа
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '402':
          description: Недостаточно средств или достигнут лимит
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '403':
          description: Ошибка доступа. Проверьте права доступа ключа
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '404':
          description: Ресурс не найден
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '408':
          description: Истекло время ожидания ответа. Повторите запрос
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '409':
          description: Конфликт состояния. Перечитайте ресурс и повторите запрос
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '413':
          description: Размер тела запроса превышает допустимый предел
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '429':
          description: Слишком много запросов. Повторите позже
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '500':
          description: Ошибка сервера. Обратитесь к поставщику услуг
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '502':
          description: Поставщик услуг вернул некорректный ответ
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
        '503':
          description: Сервис временно недоступен. Повторите позже
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorPresenter'
      security:
        - bearer: []
components:
  schemas:
    ResponseRequestDto:
      type: object
      properties:
        input:
          description: Input for response - can be string or array of input items
          oneOf:
            - type: string
              example: Hello, how are you?
            - type: array
              items:
                $ref: '#/components/schemas/InputMessageDto'
        instructions:
          type: string
          description: System instructions
          example: You are a helpful assistant
        metadata:
          type: object
          description: Metadata key-value pairs
        tools:
          type: array
          description: Tools available for the model
          items:
            oneOf:
              - 9e84592c-716d-4353-84a6-9ea989079641
              - $ref: '#/components/schemas/CustomToolDto'
              - $ref: '#/components/schemas/NamespaceToolDto'
              - c99c386c-a0d5-48d2-8d85-2eb063641127
              - 0f65c056-9f62-460b-b25d-77ae9ad87853
              - 4285fbe0-5353-49d6-96dd-862228a247cb
              - $ref: '#/components/schemas/ServerToolDefinitionDto'
        tool_choice:
          description: Tool choice strategy
          oneOf:
            - type: string
              enum:
                - none
                - auto
                - required
            - 0657f175-167f-40e7-bfc4-94c6e8980ccb
          example: auto
        parallel_tool_calls:
          type: boolean
          description: Enable parallel tool calls
          default: false
        background:
          type: boolean
          description: >-
            Запуск response в background-режиме OpenAI. В Polza provider path не
            запускает отдельную async job и игнорируется.
          deprecated: true
        include:
          description: >-
            Дополнительные поля output, которые OpenAI может включить в ответ.
            Поддерживается как входной параметр совместимости; конкретные
            include зависят от провайдера.
          example:
            - reasoning.encrypted_content
          type: array
          items:
            type: string
        model:
          type: string
          description: >-
            Идентификатор модели. Для Responses API поддерживается alias-формат
            <model>@<key>=<value>&<key>=<value>. Поддерживаемые ключи: provider,
            reasoning_effort, allow_fallbacks, data_residency. При конфликте
            alias и обычных полей body API возвращает 400. Для некоторых
            провайдеров и моделей допустимые значения reasoning_effort
            отличаются; например, Yandex AI Studio поддерживает только low,
            medium и high.
          example: >-
            openai/gpt-4o@provider=google&reasoning_effort=high&allow_fallbacks=false
        models:
          description: >-
            НЕ РЕАЛИЗОВАНО. Массив fallback-моделей пока не поддерживается
            Responses API и игнорируется. Для fallback используйте alias-формат
            в model: <model>@allow_fallbacks=true.
          deprecated: true
          type: array
          items:
            type: string
        text:
          description: Text output configuration
          allOf:
            - $ref: '#/components/schemas/TextConfigDto'
        reasoning:
          description: Reasoning configuration
          allOf:
            - $ref: '#/components/schemas/ReasoningConfigDto'
        max_output_tokens:
          type: number
          description: Maximum output tokens
          example: 1000
        temperature:
          type: number
          description: Temperature (0-2)
          example: 0.7
          minimum: 0
          maximum: 2
        top_p:
          type: number
          description: Top P (nucleus sampling)
          example: 1
          minimum: 0
          maximum: 1
        top_logprobs:
          type: number
          description: Top logprobs
          example: 5
          minimum: 0
          maximum: 20
        max_tool_calls:
          type: number
          description: >-
            Максимум вызовов серверных инструментов за запрос (по умолчанию 10,
            потолок 30; без polza:* — лимит вызовов провайдера)
          example: 5
        stop_server_tools_when:
          description: >-
            Условия остановки цикла серверных инструментов: step_count заменяет
            max_tool_calls (потолок 30), spend_cap добавляет мягкий потолок
            расходов на запрос: он проверяется перед каждым шагом модели,
            поэтому итог может превысить spend_cap на шаг, на котором потолок
            пройден, его инструменты и финальный ответ
          type: array
          items:
            $ref: '#/components/schemas/StopServerToolsWhenDto'
        prompt:
          type: object
          description: >-
            Конфигурация reusable prompt OpenAI. В текущем provider path Polza
            не загружает prompt templates и игнорирует поле.
          deprecated: true
        presence_penalty:
          type: number
          description: Presence penalty (-2 to 2)
          minimum: -2
          maximum: 2
        frequency_penalty:
          type: number
          description: Frequency penalty (-2 to 2)
          minimum: -2
          maximum: 2
        top_k:
          type: number
          description: Top K sampling
          example: 40
        image_config:
          type: object
          description: Image configuration options
        modalities:
          type: array
          description: Output modalities
          items:
            type: string
            enum:
              - text
              - image
        prompt_cache_key:
          type: string
          description: Prompt cache key
        prompt_cache_retention:
          type: string
          description: Prompt cache retention policy
          enum:
            - in_memory
            - 24h
        prompt_cache_options:
          description: Настройки кэша начала запроса (OpenAI)
          example:
            ttl: 12h
          allOf:
            - $ref: '#/components/schemas/PromptCacheOptionsDto'
        previous_response_id:
          type: string
          description: >-
            НЕ РЕАЛИЗОВАНО. Продолжение по previous_response_id пока не
            поддерживается и игнорируется. Для мультитёрн-сессий передавайте
            историю целиком в input.
          deprecated: true
        conversation:
          type: object
          description: >-
            Conversation ID/object OpenAI Responses API. В Polza stateless path
            не подтягивает историю по conversation; передавайте историю целиком
            в input.
          deprecated: true
        context_management:
          type: array
          description: >-
            Context management OpenAI Responses API. Локальная компактизация
            доступна через /v1/responses/compact; поле принимается для
            совместимости.
        moderation:
          type: object
          description: Input/output moderation configuration
        stream:
          type: boolean
          description: Enable streaming
          default: false
        stream_options:
          description: Опции стриминга
          allOf:
            - $ref: '#/components/schemas/ResponsesStreamOptionsDto'
        provider:
          description: Provider preferences
          allOf:
            - $ref: '#/components/schemas/ProviderDto'
        user:
          type: string
          description: >-
            Уникальный идентификатор конечного пользователя для отслеживания и
            предотвращения злоупотреблений
          example: user-123
        safety_identifier:
          type: string
          description: >-
            Stable hashed end-user identifier from OpenAI Responses API. Если
            user не указан, используется как user для downstream provider.
          example: sha256-user-id
        service_tier:
          type: string
          description: OpenAI service tier
          enum:
            - auto
            - default
            - flex
            - scale
            - priority
        store:
          type: boolean
          description: Whether to store generated response for later retrieval
        truncation:
          type: string
          description: Truncation strategy
          enum:
            - auto
            - disabled
        session_id:
          type: string
          description: >-
            НЕ РЕАЛИЗОВАНО. session_id не пробрасывается провайдеру (OpenRouter
            Chat Completions API не поддерживает это поле) и игнорируется. Для
            отслеживания сессий используйте metadata или user.
          deprecated: true
        plugins:
          description: >-
            Плагины для расширения функциональности (web search, file parser и
            др.)
          type: array
          items:
            type: string
      required:
        - input
        - model
    ResponsePresenter:
      type: object
      properties:
        id:
          type: string
          description: Response ID
          example: gen_581761234567890123
        object:
          type: string
          enum:
            - response
          example: response
        created_at:
          type: number
          description: Created timestamp
          example: 1234567890
        completed_at:
          type: object
          description: Completed timestamp
          nullable: true
        model:
          type: string
          description: Model used
          example: openai/gpt-4
        status:
          type: string
          enum:
            - completed
            - incomplete
            - in_progress
            - failed
            - cancelled
            - queued
          example: completed
        error:
          description: Error information
          nullable: true
          allOf:
            - $ref: '#/components/schemas/ResponseErrorPresenter'
        output_text:
          type: string
          description: Full output text (convenience field)
          example: Hello! How can I help you today?
        output:
          type: array
          description: Output items array
        incomplete_details:
          type: object
          description: Details about incomplete response
          nullable: true
        usage:
          description: Usage information
          allOf:
            - $ref: '#/components/schemas/ResponsesUsagePresenter'
        metadata:
          type: object
          description: Metadata key-value pairs
        polza:
          type: object
          description: >-
            Есть, если модель вызывала серверные инструменты:
            parent_generation_id — id запроса в истории (GET
            /v1/history/generations/{id}), шаги — /children
      required:
        - id
        - object
        - created_at
        - model
        - status
        - output_text
        - output
    ApiErrorPresenter:
      type: object
      properties:
        error:
          description: Информация об ошибке
          allOf:
            - $ref: '#/components/schemas/ApiErrorBodyPresenter'
        trace_id:
          type: string
          description: ID трассировки запроса
          example: 550e8400-e29b-41d4-a716-446655440000
      required:
        - error
    InputMessageDto:
      type: object
      properties:
        type:
          type: string
          enum:
            - message
          example: message
        role:
          type: string
          enum:
            - user
            - assistant
            - system
            - developer
          example: user
        content:
          description: Контент сообщения — строка или массив content items
          oneOf:
            - type: string
              example: Hello!
            - type: array
              items:
                oneOf:
                  - 3635f19d-d05d-4915-b541-b4796ce44670
                  - b849f6a1-894e-47c2-be83-8f6115e8577e
                  - 6906f3ec-3d6a-4ca4-bc6f-d1db222ae0cc
                  - 453e43c2-3935-46c4-8176-0fa7abc93a70
                  - 0021f57d-e8d3-4947-b403-51a4c844a769
        id:
          type: string
          description: Message ID
          example: msg_abc123
        status:
          type: string
          description: Message status
          enum:
            - completed
            - in_progress
            - incomplete
      required:
        - role
        - content
    CustomToolDto:
      type: object
      properties:
        type:
          type: string
          enum:
            - custom
          example: custom
        name:
          type: string
          description: Custom tool name
          example: apply_patch
        description:
          type: string
          description: Custom tool description
        format:
          type: object
          description: Free-form input format/grammar configuration
      required:
        - type
        - name
    NamespaceToolDto:
      type: object
      properties:
        type:
          type: string
          enum:
            - namespace
          example: namespace
        name:
          type: string
          description: Имя группы инструментов
          example: functions
        description:
          type: string
          description: Описание группы
          example: ''
        tools:
          type: array
          description: 'Инструменты группы: function или custom'
          items:
            type: object
      required:
        - type
        - name
        - tools
    ServerToolDefinitionDto:
      type: object
      properties:
        type:
          type: string
          description: >-
            Тип серверного инструмента с префиксом polza: (openrouter:
            принимается как алиас); список — GET /v1/server-tools
          example: polza:web_search
        parameters:
          type: object
          description: >-
            Параметры инструмента по его parameters_schema из GET
            /v1/server-tools (например max_results, max_uses для
            polza:web_search). В Responses API параметры можно передать и рядом
            с type
          example:
            max_results: 5
            max_uses: 3
      required:
        - type
    TextConfigDto:
      type: object
      properties:
        format:
          description: Output format configuration
          oneOf:
            - type: string
              enum:
                - text
                - json_object
            - c471fcf1-6604-46c6-9132-3c5c5edf594f
            - 8e5ee072-09f0-42cd-8e3c-8e6cb3ae0e10
            - bab669c4-f16d-4010-b879-2392ed60264b
          example: text
        verbosity:
          type: string
          description: Verbosity level
          enum:
            - high
            - medium
            - low
          example: medium
    ReasoningConfigDto:
      type: object
      properties:
        type:
          type: string
          description: >-
            Тип reasoning: adaptive — автовыбор глубины (Claude 4.6+), disabled
            — отключить. Для Claude 4.6+ используйте adaptive вместо
            enabled+max_tokens
          enum:
            - adaptive
            - disabled
          example: adaptive
        effort:
          type: string
          description: >-
            Уровень усилий reasoning. Для некоторых провайдеров и моделей
            допустимые значения могут отличаться. Проверяйте документацию
            конкретного провайдера. Например, для Yandex AI Studio
            поддерживаются только low, medium и high.
          enum:
            - max
            - xhigh
            - high
            - medium
            - low
            - minimal
            - none
          example: medium
        effort_level:
          type: string
          description: >-
            Уровень усилий для adaptive thinking (Claude 4.6+). Значение max
            даёт максимальную глубину мышления. Используется вместо effort для
            Claude 4.6+ моделей
          example: max
        summary:
          type: string
          description: Режим summary для reasoning
          enum:
            - auto
            - concise
            - detailed
          example: auto
        max_tokens:
          type: number
          description: Максимальное количество токенов для reasoning
          example: 5000
        enabled:
          type: boolean
          description: Включить/выключить reasoning явно
          example: true
    StopServerToolsWhenDto:
      type: object
      properties:
        type:
          type: string
          description: Тип условия
          enum:
            - step_count
            - spend_cap
          example: step_count
        value:
          type: number
          description: >-
            Для step_count: максимум вызовов серверных инструментов (больше 30 —
            считается как 30)
          example: 5
        value_rub:
          type: number
          description: >-
            Для spend_cap: потолок расходов на запрос (модель и инструменты),
            RUB
          example: 20
      required:
        - type
    PromptCacheOptionsDto:
      type: object
      properties:
        ttl:
          type: string
          description: >-
            Сколько хранить кэш начала запроса у OpenAI: 30m — около 30 минут
            (по умолчанию), 12h — не меньше 12 часов. Запись в 12-часовой кэш
            стоит вдвое дороже обычной записи, чтение — как обычно. Другие
            провайдеры поле не используют.
          enum:
            - 30m
            - 12h
          example: 12h
      required:
        - ttl
    ResponsesStreamOptionsDto:
      type: object
      properties:
        include_obfuscation:
          type: boolean
          description: Обфускация SSE-полей (совместимость OpenAI)
          example: false
        hide_intermediate_text:
          type: boolean
          description: >-
            Не отдавать текст промежуточных шагов цикла серверных инструментов;
            финальный ответ приходит одним куском после завершения шага
          example: false
    ProviderDto:
      type: object
      properties:
        require_parameters:
          type: boolean
          description: Требовать от провайдера поддержку всех переданных параметров
          example: true
        allow_fallbacks:
          type: boolean
          description: Разрешить использование резервных провайдеров
          example: true
        order:
          description: Упорядоченный список slug провайдеров для использования
          example:
            - OpenAI
            - Anthropic
          type: array
          items:
            type: string
        only:
          description: Список разрешенных slug провайдеров
          example:
            - OpenAI
            - Google
          type: array
          items:
            type: string
        ignore:
          description: Список игнорируемых slug провайдеров
          example:
            - DeepInfra
          type: array
          items:
            type: string
        sort:
          type: string
          description: Критерий сортировки провайдеров
          enum:
            - price
            - throughput
            - latency
          example: price
        data_residency:
          type: string
          description: >-
            Где должны обрабатываться данные запроса. `ru` — только провайдеры
            на инфраструктуре в РФ, в том числе при fallback. Если таких
            провайдеров у модели нет, запрос отклоняется, а не уходит к
            иностранному провайдеру. Распознавание PDF (OCR) плагина
            polza-pdf-parser при `ru` отключается: текст извлекается без него.
            Инструменты, которые клиент включает сам (веб-поиск, web_fetch),
            параметр не ограничивает: они обращаются к внешним сервисам
          enum:
            - ru
          example: ru
        max_price:
          description: Максимальные цены для запроса
          allOf:
            - $ref: '#/components/schemas/ProviderMaxPriceDto'
    ResponseErrorPresenter:
      type: object
      properties:
        code:
          type: string
          description: Error code
          example: invalid_request
        message:
          type: string
          description: Error message
          example: Invalid input format
        metadata:
          type: object
          description: Error metadata
      required:
        - code
        - message
    ResponsesUsagePresenter:
      type: object
      properties:
        input_tokens:
          type: number
          description: Количество входных токенов
          example: 10
        output_tokens:
          type: number
          description: Количество выходных токенов
          example: 20
        total_tokens:
          type: number
          description: Общее количество токенов
          example: 30
        prompt_tokens:
          type: number
          description: Количество токенов в промпте (alias для input_tokens)
          example: 10
        completion_tokens:
          type: number
          description: Количество токенов в ответе (alias для output_tokens)
          example: 20
        input_tokens_details:
          description: Детализация входных токенов
          nullable: true
          allOf:
            - $ref: '#/components/schemas/PromptTokensDetailsPresenter'
        output_tokens_details:
          description: Детализация выходных токенов
          nullable: true
          allOf:
            - $ref: '#/components/schemas/CompletionTokensDetailsPresenter'
        prompt_tokens_details:
          description: Детализация токенов промпта (alias для input_tokens_details)
          nullable: true
          allOf:
            - $ref: '#/components/schemas/PromptTokensDetailsPresenter'
        completion_tokens_details:
          description: Детализация токенов completion (alias для output_tokens_details)
          nullable: true
          allOf:
            - $ref: '#/components/schemas/CompletionTokensDetailsPresenter'
        server_tool_use:
          description: >-
            Число исполненных вызовов серверных инструментов по типам:
            web_search_requests, web_fetch_requests, datetime_requests, …
          nullable: true
          allOf:
            - $ref: '#/components/schemas/ServerToolUsePresenter'
        cost_rub:
          type: object
          description: Стоимость запроса в рублях (списано с баланса клиента)
          example: 0.04131306
          nullable: true
        cost:
          type: object
          description: Стоимость запроса в рублях (alias для cost_rub)
          example: 0.04131306
          nullable: true
        cost_details:
          type: object
          description: >-
            Разбивка cost_rub запроса с серверными инструментами: модель (все
            шаги) и инструменты, RUB
          example:
            inference_cost_rub: 1.2
            server_tools_cost_rub: 0.4
          nullable: true
        server_tool_use_details:
          type: object
          description: >-
            Детали цикла серверных инструментов: исполненные вызовы по типам,
            запрошенные и исполненные вызовы всего, число шагов модели
          example:
            web_search_requests: 1
            tool_calls_requested: 2
            tool_calls_executed: 2
            steps: 2
          nullable: true
        plugins:
          type: object
          description: Детализация серверных плагинов
          example:
            masker:
              operations:
                - operation: mask
                  latency_ms: 15
                  cost_rub: 0.001
                - operation: unmask
                  latency_ms: 8
                  cost_rub: 0
              total_cost_rub: 0.001
          nullable: true
        plugin_post_process_error:
          type: boolean
          description: >-
            Ошибка post-process плагинов (ответ возвращён в замаскированном
            виде)
          example: true
        guard:
          description: >-
            Проверка запроса политикой защиты данных организации; нет поля —
            политика к ключу не назначена
          allOf:
            - $ref: '#/components/schemas/GuardUsagePresenter'
      required:
        - input_tokens
        - output_tokens
        - total_tokens
        - prompt_tokens
        - completion_tokens
    ApiErrorBodyPresenter:
      type: object
      properties:
        code:
          type: string
          description: Код ошибки
          enum:
            - BAD_REQUEST
            - UNAUTHORIZED
            - api_key_revoked
            - INSUFFICIENT_BALANCE
            - FORBIDDEN
            - guard_no_route
            - guard_blocked
            - unofficial_providers_disabled
            - NOT_FOUND
            - REQUEST_TIMEOUT
            - CONFLICT
            - PAYLOAD_TOO_LARGE
            - TOO_MANY_REQUESTS
            - BAD_GATEWAY
            - SERVICE_UNAVAILABLE
            - INTERNAL_ERROR
          example: BAD_REQUEST
        message:
          type: string
          description: Описание ошибки
          example: Недопустимое значение параметра
        trace_id:
          type: string
          description: ID трассировки запроса
          example: 550e8400-e29b-41d4-a716-446655440000
        details:
          type: object
          description: Уточняющие поля ошибки. Отдаются только для 4xx
          additionalProperties: true
        metadata:
          description: Метаданные ошибки провайдера
          allOf:
            - $ref: '#/components/schemas/ApiErrorMetadataPresenter'
      required:
        - code
        - message
    ProviderMaxPriceDto:
      type: object
      properties:
        prompt:
          type: number
          description: Максимальная цена за промпт токены (RUB за миллион токенов)
          example: 10
        completion:
          type: number
          description: Максимальная цена за completion токены (RUB за миллион токенов)
          example: 20
        image:
          type: number
          description: Максимальная цена за изображение (RUB за штуку)
          example: 5
        audio:
          type: number
          description: Максимальная цена за аудио (RUB за миллион токенов)
          example: 15
        request:
          type: number
          description: Максимальная цена за запрос (RUB за запрос)
          example: 1
        video_per_second:
          type: number
          description: Максимальная цена за секунду видео (RUB за секунду)
          example: 50
        stt_per_minute:
          type: number
          description: Максимальная цена распознавания речи (RUB за минуту)
          example: 5
        tts_per_million_characters:
          type: number
          description: Максимальная цена синтеза речи (RUB за миллион символов)
          example: 1500
    PromptTokensDetailsPresenter:
      type: object
      properties:
        cached_tokens:
          type: number
          description: Кэшированные токены
          example: 0
        cache_write_tokens:
          type: number
          description: Токены, записанные в prompt cache
          example: 0
        audio_tokens:
          type: number
          description: Аудио токены в промпте
          example: 0
        video_tokens:
          type: number
          description: Видео токены в промпте
          example: 0
    CompletionTokensDetailsPresenter:
      type: object
      properties:
        reasoning_tokens:
          type: object
          description: Токены reasoning (для reasoning моделей)
          example: 100
          nullable: true
        audio_tokens:
          type: object
          description: Аудио токены в ответе
          example: 0
          nullable: true
        image_tokens:
          type: object
          description: Токены изображений в ответе
          example: 0
          nullable: true
        accepted_prediction_tokens:
          type: object
          description: Принятые токены предсказаний
          example: 0
          nullable: true
        rejected_prediction_tokens:
          type: object
          description: Отклоненные токены предсказаний
          example: 0
          nullable: true
    ServerToolUsePresenter:
      type: object
      properties:
        web_search_requests:
          type: number
          description: Количество вызовов веб-поиска
          example: 1
    GuardUsagePresenter:
      type: object
      properties:
        policy_version_id:
          type: string
          description: Версия политики защиты, по которой проверен запрос
          example: 3f2c1a9e-7b4d-4e8a-9c1f-2d5e6a7b8c9d
        action:
          type: string
          description: >-
            Что сделано с найденными данными: none — ничего не нашлось, audit —
            только записано, mask — заменено на [ТИП] до отправки модели
          enum:
            - none
            - audit
            - mask
            - pseudonymize
            - block
          example: mask
        entities:
          type: object
          description: Сколько данных каждого типа нашлось в запросе
          additionalProperties:
            type: number
          example:
            PHONE: 1
            EMAIL: 1
        layers:
          description: Какими способами проверялся запрос
          example:
            - regex
          type: array
          items:
            type: string
        degraded:
          type: boolean
          description: true — часть проверок была недоступна и запрос проверен не полностью
          example: false
      required:
        - policy_version_id
        - action
        - entities
        - layers
        - degraded
    ApiErrorMetadataPresenter:
      type: object
      properties:
        reason:
          type: string
          description: 'Машинная причина отказа: по ней можно ветвиться, не разбирая текст'
          example: noProvidersForModel
        raw:
          type: string
          description: Исходный текст ответа провайдера
          example: The parameter `duration` specified in the request is not valid
        provider_name:
          type: string
          description: Провайдер, вернувший ошибку
          example: alibaba
  securitySchemes:
    bearer:
      scheme: bearer
      bearerFormat: API Key
      type: http
      description: >-
        API ключ передаётся в заголовке: Authorization: Bearer
        <POLZA_AI_API_KEY>

````

This documentation is built and hosted on [Mintlify](https://mintlify.com), a developer documentation platform.