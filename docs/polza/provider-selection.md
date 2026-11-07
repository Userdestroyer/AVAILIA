> ## Documentation Index
> Fetch the complete documentation index at: https://polza.ai/docs/llms.txt
> Use this file to discover all available pages before exploring further.

# Выбор провайдера

> Как выбирать и переключаться между провайдерами моделей

Polza.ai предоставляет доступ к моделям от разных провайдеров. В этом гайде вы узнаете, как выбирать оптимального провайдера для ваших задач.

## Что такое провайдер?

Провайдер — это компания или сервис, предоставляющий доступ к AI моделям. Одна и та же модель может быть доступна через разных провайдеров с разной ценой, скоростью и доступностью.

## Доступные провайдеры

> [card] OpenAI
>
> GPT-4o, GPT-4o-mini, o3 и др.

> [card] Anthropic
>
> Claude Sonnet, Claude Opus и др.

> [card] Google
>
> Gemini Pro, Gemini Flash и др.

> [card] Meta\*
>
> Llama и др.

> [card] DeepSeek
>
> DeepSeek V3, DeepSeek R1 и др.

> [card] Другие
>
> Mistral, Qwen и др.

## Как выбрать провайдера

### Автоматический выбор (рекомендуется)

По умолчанию Polza.ai автоматически выбирает оптимального провайдера на основе доступности, скорости и стоимости. Не нужно передавать параметр `provider` — система сама определит лучший вариант.

```typescript TypeScript theme={null}
// Автоматический выбор провайдера — ничего указывать не нужно
const completion = await openai.chat.completions.create({
  model: 'openai/gpt-4o',
  messages: [{ role: 'user', content: 'Привет!' }]
});
```

```python Python theme={null}
# Автоматический выбор провайдера — ничего указывать не нужно
completion = client.chat.completions.create(
    model="openai/gpt-4o",
    messages=[{"role": "user", "content": "Привет!"}]
)
```

### Параметр `provider`

Для управления выбором провайдера передайте объект `provider` в запросе:

| Поле | Тип | Описание |
| - | - | - |
| `order` | string\[] | Приоритетный порядок провайдеров |
| `only` | string\[] | Белый список — использовать только указанных провайдеров |
| `ignore` | string\[] | Чёрный список — исключить указанных провайдеров |
| `sort` | string | Сортировка: `price`, `latency` или `throughput` |
| `max_price` | object | Максимальные цены (см. ниже) |
| `allow_fallbacks` | boolean | Разрешить фолбэк на другие провайдеры при ошибке |

> [!TIP]
>
> Закреплённый провайдер может заметно снизить цену: в тесте на 40 описаниях товаров запросы без закрепления стоили 1,36 ₽, с закреплённым самым дешёвым провайдером — 0,67 ₽. Как найти такого провайдера — в сценарии [SEO-тексты через API](https://polza.ai/use-cases/seo-content#stoimost).

### Поля `max_price`

| Поле | Тип | Описание |
| - | - | - |
| `prompt` | number | Макс. цена за промпт (RUB за миллион токенов) |
| `completion` | number | Макс. цена за completion (RUB за миллион токенов) |
| `image` | number | Макс. цена за изображение (RUB) |
| `audio` | number | Макс. цена за аудио (RUB за миллион токенов) |
| `request` | number | Макс. цена за запрос (RUB) |

### Alias в строке `model`

Если ваш клиент не поддерживает дополнительные поля в body (например, некоторые OpenAI-совместимые SDK), часть routing-настроек можно передать прямо в строке `model` через `@`-синтаксис:

```text theme={null}
<model>@<key>=<value>&<key>=<value>
```

Поддерживаемые alias-параметры:

| Alias | Что делает |
| - | - |
| `provider` | Выбирает конкретного провайдера (`provider.only`) |
| `reasoning_effort` | Управляет глубиной reasoning (`low` / `medium` / `high` и др.) |
| `allow_fallbacks` | Разрешает или запрещает fallback (`true` / `false`) |

Примеры:

```text theme={null}
anthropic/claude-opus-4-6@provider=Amazon Bedrock
minimax/minimax-m2.5@reasoning_effort=high
minimax/minimax-m2.5@allow_fallbacks=false
minimax/minimax-m2.5@provider=DeepInfra&reasoning_effort=high&allow_fallbacks=false
```

> [!NOTE]
>
> Alias-формат работает только для `/v1/chat/completions`, `/v1/responses` и `/v1/messages`. Embeddings, audio и media не поддерживаются.

> [!WARNING]
>
> Если один и тот же параметр задан и в alias, и в body (например, `reasoning_effort` в строке model и `reasoning.effort` в теле запроса), API вернёт `400`. Для сложного routing (`order`, `ignore`, `max_price`) используйте обычный body-контракт.

Подробнее: [Алиасы параметров в model](https://polza.ai/docs/osobennosti/aliases.md)

## Примеры

### Приоритет провайдеров

Укажите предпочтительный порядок провайдеров. Если первый недоступен — запрос уйдёт ко второму.

```typescript TypeScript theme={null}
const completion = await openai.chat.completions.create({
  model: 'openai/gpt-4o',
  messages: [{ role: 'user', content: 'Привет!' }],
  // @ts-ignore - расширенный параметр Polza.ai
  provider: {
    order: ['OpenAI', 'Anthropic'],
    allow_fallbacks: true
  }
});
```

```python Python theme={null}
completion = client.chat.completions.create(
    model="openai/gpt-4o",
    messages=[{"role": "user", "content": "Привет!"}],
    extra_body={
        "provider": {
            "order": ["OpenAI", "Anthropic"],
            "allow_fallbacks": True
        }
    }
)
```

```bash cURL theme={null}
curl -X POST "https://polza.ai/api/v1/chat/completions" \
  -H "Authorization: Bearer <POLZA_AI_API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "openai/gpt-4o",
    "messages": [{"role": "user", "content": "Привет!"}],
    "provider": {
      "order": ["OpenAI", "Anthropic"],
      "allow_fallbacks": true
    }
  }'
```

### Белый список (only)

Ограничьте запрос только указанными провайдерами:

```typescript TypeScript theme={null}
const completion = await openai.chat.completions.create({
  model: 'openai/gpt-4o',
  messages: [{ role: 'user', content: 'Привет!' }],
  // @ts-ignore
  provider: {
    only: ['OpenAI']
  }
});
```

```python Python theme={null}
completion = client.chat.completions.create(
    model="openai/gpt-4o",
    messages=[{"role": "user", "content": "Привет!"}],
    extra_body={
        "provider": {
            "only": ["OpenAI"]
        }
    }
)
```

```bash cURL theme={null}
curl -X POST "https://polza.ai/api/v1/chat/completions" \
  -H "Authorization: Bearer <POLZA_AI_API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "openai/gpt-4o",
    "messages": [{"role": "user", "content": "Привет!"}],
    "provider": {
      "only": ["OpenAI"]
    }
  }'
```

### Чёрный список (ignore)

Исключите определённых провайдеров:

```json theme={null}
{
  "provider": {
    "ignore": ["DeepInfra", "Together"]
  }
}
```

### Сортировка по цене / скорости

```json Самый дешёвый theme={null}
{
  "provider": {
    "sort": "price"
  }
}
```

```json Самый быстрый (задержка) theme={null}
{
  "provider": {
    "sort": "latency"
  }
}
```

```json Самый быстрый (пропускная способность) theme={null}
{
  "provider": {
    "sort": "throughput"
  }
}
```

### Фильтрация по максимальной цене

Ограничьте стоимость запроса:

```json theme={null}
{
  "provider": {
    "sort": "price",
    "max_price": {
      "prompt": 10,
      "completion": 20
    }
  }
}
```

### Комбинирование параметров

Все параметры можно комбинировать:

```json theme={null}
{
  "provider": {
    "only": ["OpenAI", "Anthropic"],
    "sort": "price",
    "max_price": {
      "prompt": 15,
      "completion": 30
    },
    "allow_fallbacks": true
  }
}
```

## Получение информации о провайдере

В ответе API можно увидеть, какой провайдер обработал запрос:

```json theme={null}
{
  "id": "gen_123",
  "model": "openai/gpt-4o",
  "provider": "openai-direct",
  "choices": [...],
  "usage": {...}
}
```

## Советы и лучшие практики

> [accordion] Используйте автовыбор для production
>
> Автоматический выбор провайдера обеспечивает лучшую доступность и оптимальную цену. При недоступности одного провайдера система автоматически переключится на другой.

> [accordion] Используйте allow\_fallbacks
>
> При явном выборе провайдера включайте `allow_fallbacks: true`, чтобы запрос не упал при временной недоступности выбранного провайдера.

> [accordion] Тестируйте на разных провайдерах
>
> При разработке проверяйте, что ваш код работает с разными провайдерами — могут быть небольшие различия в ответах.

> [accordion] Мониторьте затраты
>
> Разные провайдеры имеют разные цены. Используйте [статистику использования](https://polza.ai/docs/osobennosti/usage.md) для контроля расходов.

## Следующие шаги

> [card] [Каталог моделей](https://polza.ai/docs/gaidy/models.md)
>
> Полный список доступных моделей

> [card] [Алиасы параметров](https://polza.ai/docs/osobennosti/aliases.md)
>
> Передача provider и reasoning\_effort прямо в строке model

> [card] [Статистика](https://polza.ai/docs/osobennosti/usage.md)
>
> Мониторинг использования и затрат

***

\* Meta признана экстремистской организацией и запрещена в Российской Федерации.


This documentation is built and hosted on [Mintlify](https://mintlify.com), a developer documentation platform.