package stream

import (
	"bufio"
	"encoding/json"
	"errors"
	"io"
	"strings"
)

// Форматы API провайдера — совпадают с ChatRequest::FORMAT_* в Laravel
const (
	FormatChat      = "chat"
	FormatResponses = "responses"
	FormatMessages  = "messages"
)

// Chunk — кусок ответа для браузера: текст, рассуждения или изменение поиска.
type Chunk struct {
	Kind   string // "delta" | "reasoning" | "search"
	Text   string
	Search *Search
}

// Search — один поиск модели в интернете (пузырёк над ответом).
type Search struct {
	ID     string `json:"id"`
	Status string `json:"status"` // "searching" | "done" | "failed"
	Query  string `json:"query,omitempty"`
	// Оплачиваемый ли это поиск (открытие страницы — нет)
	billable bool
}

// Result — что собрали за стрим.
type Result struct {
	Content  string
	Usage    json.RawMessage
	Searches []Search
	// Сколько поисков оплачено — из usage, а если провайдер не сообщил, по числу запросов
	WebSearches int
}

// Read разбирает стрим ответа в формате format; на каждый кусок вызывает emit.
func Read(format string, r io.Reader, emit func(Chunk)) (Result, error) {
	switch format {
	case FormatResponses:
		return readResponses(r, emit)
	case FormatMessages:
		return readMessages(r, emit)
	default:
		return readChat(r, emit)
	}
}

// readSSE отдаёт payload каждой строки "data:"; [DONE] и EOF — конец стрима.
func readSSE(r io.Reader, handle func(payload []byte) (stop bool, err error)) error {
	reader := bufio.NewReader(r)

	for {
		line, readErr := reader.ReadString('\n')
		line = strings.TrimRight(line, "\r\n")

		if payload, ok := strings.CutPrefix(line, "data:"); ok {
			payload = strings.TrimSpace(payload)
			if payload == "[DONE]" {
				return nil
			}
			if stop, err := handle([]byte(payload)); err != nil || stop {
				return err
			}
		}

		if readErr != nil {
			if errors.Is(readErr, io.EOF) {
				// Поток закрылся без явного конца — отдаём что успели собрать
				return nil
			}
			return readErr
		}
	}
}

// searchLog — поиски по порядку появления, обновляются по id.
type searchLog struct {
	items []Search
	index map[string]int
}

func (l *searchLog) upsert(s Search) Search {
	if l.index == nil {
		l.index = map[string]int{}
	}
	if i, ok := l.index[s.ID]; ok {
		if s.Query == "" {
			s.Query = l.items[i].Query
		}
		s.billable = s.billable || l.items[i].billable
		l.items[i] = s
		return s
	}
	l.index[s.ID] = len(l.items)
	l.items = append(l.items, s)
	return s
}

func (l *searchLog) billable() int {
	n := 0
	for _, s := range l.items {
		if s.billable {
			n++
		}
	}
	return n
}

// ── chat/completions ────────────────────────────────────

type chatChunk struct {
	Choices []struct {
		Delta struct {
			Content          string          `json:"content"`
			ReasoningContent string          `json:"reasoning_content"`
			Reasoning        json.RawMessage `json:"reasoning"`
		} `json:"delta"`
	} `json:"choices"`
	Usage json.RawMessage `json:"usage"`
	Error *struct {
		Message string `json:"message"`
	} `json:"error"`
	// События серверных инструментов Polza (stream_options.include_server_tool_events)
	Polza *struct {
		Event     string `json:"event"`
		ID        string `json:"id"`
		Type      string `json:"type"`
		Status    string `json:"status"`
		Arguments struct {
			Query string `json:"query"`
		} `json:"arguments"`
		Summary struct {
			Query string `json:"query"`
		} `json:"summary"`
	} `json:"polza"`
}

func readChat(r io.Reader, emit func(Chunk)) (Result, error) {
	var res Result
	var content strings.Builder
	var searches searchLog

	err := readSSE(r, func(payload []byte) (bool, error) {
		var chunk chatChunk
		if json.Unmarshal(payload, &chunk) != nil {
			return false, nil
		}
		if chunk.Error != nil {
			return true, errors.New(chunk.Error.Message)
		}
		// Поиск Polza: tool_call — модель ищет (с запросом), tool_result — готово / не удалось
		if p := chunk.Polza; p != nil && p.Type == "polza:web_search" {
			switch p.Event {
			case "tool_call":
				s := searches.upsert(Search{ID: p.ID, Status: "searching", Query: p.Arguments.Query, billable: true})
				emit(Chunk{Kind: "search", Search: &s})
			case "tool_result":
				status := "failed"
				if p.Status == "completed" {
					status = "done"
				}
				s := searches.upsert(Search{ID: p.ID, Status: status, Query: p.Summary.Query})
				emit(Chunk{Kind: "search", Search: &s})
			}
		}
		for _, choice := range chunk.Choices {
			if text := reasoningText(choice.Delta.ReasoningContent, choice.Delta.Reasoning); text != "" {
				emit(Chunk{Kind: "reasoning", Text: text})
			}
			if choice.Delta.Content != "" {
				content.WriteString(choice.Delta.Content)
				emit(Chunk{Kind: "delta", Text: choice.Delta.Content})
			}
		}
		if len(chunk.Usage) > 0 && string(chunk.Usage) != "null" {
			res.Usage = chunk.Usage
		}
		return false, nil
	})

	res.Content = content.String()
	res.Searches = searches.items
	res.WebSearches = searches.billable()
	// Polza сообщает число поисков в usage (сюда же попадает и поиск «до вызова» модели)
	var usage struct {
		ServerToolUse *struct {
			WebSearchRequests int `json:"web_search_requests"`
		} `json:"server_tool_use"`
	}
	if json.Unmarshal(res.Usage, &usage) == nil && usage.ServerToolUse != nil {
		res.WebSearches = usage.ServerToolUse.WebSearchRequests
	}
	return res, err
}

// Провайдеры отдают рассуждения по-разному: reasoning_content или reasoning строкой.
func reasoningText(reasoningContent string, reasoning json.RawMessage) string {
	if reasoningContent != "" {
		return reasoningContent
	}
	var text string
	if len(reasoning) > 0 && json.Unmarshal(reasoning, &text) == nil {
		return text
	}
	return ""
}

// ── OpenAI Responses ────────────────────────────────────

type responsesEvent struct {
	Type    string `json:"type"`
	Delta   string `json:"delta"`
	Message string `json:"message"`
	Item    *struct {
		ID     string `json:"id"`
		Type   string `json:"type"`
		Status string `json:"status"`
		Action *struct {
			Type  string `json:"type"`
			Query string `json:"query"`
			URL   string `json:"url"`
		} `json:"action"`
	} `json:"item"`
	Response *struct {
		Usage json.RawMessage `json:"usage"`
		Error *struct {
			Message string `json:"message"`
		} `json:"error"`
	} `json:"response"`
}

func readResponses(r io.Reader, emit func(Chunk)) (Result, error) {
	var res Result
	var content strings.Builder
	var searches searchLog

	err := readSSE(r, func(payload []byte) (bool, error) {
		var ev responsesEvent
		if json.Unmarshal(payload, &ev) != nil {
			return false, nil
		}

		switch ev.Type {
		case "response.output_text.delta":
			content.WriteString(ev.Delta)
			emit(Chunk{Kind: "delta", Text: ev.Delta})
		case "response.reasoning_text.delta", "response.reasoning_summary_text.delta":
			emit(Chunk{Kind: "reasoning", Text: ev.Delta})
		case "response.output_item.added", "response.output_item.done":
			if ev.Item == nil || ev.Item.Type != "web_search_call" {
				break
			}
			s := Search{ID: ev.Item.ID, Status: "searching"}
			if ev.Type == "response.output_item.done" {
				s.Status = "failed"
				if ev.Item.Status == "completed" {
					s.Status = "done"
				}
			}
			if a := ev.Item.Action; a != nil {
				s.Query = a.Query
				if s.Query == "" {
					s.Query = a.URL
				}
				s.billable = a.Type == "search"
			}
			s = searches.upsert(s)
			emit(Chunk{Kind: "search", Search: &s})
		case "response.completed", "response.incomplete":
			// incomplete — упёрлись в лимит ответа: текст оборван, но это ответ, а не ошибка
			if ev.Response != nil {
				res.Usage = ev.Response.Usage
			}
			return true, nil
		case "response.failed":
			if ev.Response != nil && ev.Response.Error != nil {
				return true, errors.New(ev.Response.Error.Message)
			}
			return true, errors.New("провайдер не смог ответить")
		case "error":
			return true, errors.New(ev.Message)
		}
		return false, nil
	})

	res.Content = content.String()
	res.Searches = searches.items
	// Gemini сообщает число поисков в usage; GPT/Claude — нет, считаем запросы сами
	var usage struct {
		InputTokensDetails struct {
			WebSearchRequests int `json:"web_search_requests"`
		} `json:"input_tokens_details"`
	}
	if json.Unmarshal(res.Usage, &usage) == nil && usage.InputTokensDetails.WebSearchRequests > 0 {
		res.WebSearches = usage.InputTokensDetails.WebSearchRequests
	} else {
		res.WebSearches = searches.billable()
	}
	return res, err
}

// ── Anthropic Messages ──────────────────────────────────

type messagesEvent struct {
	Type    string `json:"type"`
	Index   int    `json:"index"`
	Message *struct {
		Usage map[string]any `json:"usage"`
	} `json:"message"`
	ContentBlock *struct {
		Type      string          `json:"type"`
		ID        string          `json:"id"`
		Name      string          `json:"name"`
		ToolUseID string          `json:"tool_use_id"`
		Content   json.RawMessage `json:"content"`
	} `json:"content_block"`
	Delta *struct {
		Type        string `json:"type"`
		Text        string `json:"text"`
		Thinking    string `json:"thinking"`
		PartialJSON string `json:"partial_json"`
	} `json:"delta"`
	Usage map[string]any `json:"usage"`
	Error *struct {
		Message string `json:"message"`
	} `json:"error"`
}

func readMessages(r io.Reader, emit func(Chunk)) (Result, error) {
	var res Result
	var content strings.Builder
	var searches searchLog
	usage := map[string]any{}
	// Индекс блока server_tool_use → id поиска и копящийся JSON его аргументов
	toolBlocks := map[int]*struct {
		id   string
		args strings.Builder
	}{}

	err := readSSE(r, func(payload []byte) (bool, error) {
		var ev messagesEvent
		if json.Unmarshal(payload, &ev) != nil {
			return false, nil
		}

		switch ev.Type {
		case "message_start":
			if ev.Message != nil {
				for k, v := range ev.Message.Usage {
					usage[k] = v
				}
			}
		case "content_block_start":
			block := ev.ContentBlock
			if block == nil {
				break
			}
			switch block.Type {
			case "server_tool_use":
				if block.Name != "web_search" {
					break
				}
				toolBlocks[ev.Index] = &struct {
					id   string
					args strings.Builder
				}{id: block.ID}
				s := searches.upsert(Search{ID: block.ID, Status: "searching", billable: true})
				emit(Chunk{Kind: "search", Search: &s})
			case "web_search_tool_result":
				// Список результатов — успех; объект web_search_tool_result_error — неудача
				status := "done"
				if !strings.HasPrefix(strings.TrimSpace(string(block.Content)), "[") {
					status = "failed"
				}
				s := searches.upsert(Search{ID: block.ToolUseID, Status: status})
				emit(Chunk{Kind: "search", Search: &s})
			}
		case "content_block_delta":
			if ev.Delta == nil {
				break
			}
			switch ev.Delta.Type {
			case "text_delta":
				content.WriteString(ev.Delta.Text)
				emit(Chunk{Kind: "delta", Text: ev.Delta.Text})
			case "thinking_delta":
				emit(Chunk{Kind: "reasoning", Text: ev.Delta.Thinking})
			case "input_json_delta":
				if block, ok := toolBlocks[ev.Index]; ok {
					block.args.WriteString(ev.Delta.PartialJSON)
				}
			}
		case "content_block_stop":
			// Аргументы поиска пришли целиком — показываем запрос
			if block, ok := toolBlocks[ev.Index]; ok {
				var args struct {
					Query string `json:"query"`
				}
				if json.Unmarshal([]byte(block.args.String()), &args) == nil && args.Query != "" {
					s := searches.upsert(Search{ID: block.id, Status: "searching", Query: args.Query})
					emit(Chunk{Kind: "search", Search: &s})
				}
				delete(toolBlocks, ev.Index)
			}
		case "message_delta":
			for k, v := range ev.Usage {
				usage[k] = v
			}
		case "message_stop":
			return true, nil
		case "error":
			if ev.Error != nil {
				return true, errors.New(ev.Error.Message)
			}
			return true, errors.New("провайдер не смог ответить")
		}
		return false, nil
	})

	res.Content = content.String()
	res.Searches = searches.items
	res.Usage, _ = json.Marshal(usage)
	res.WebSearches = searches.billable()
	if tool, ok := usage["server_tool_use"].(map[string]any); ok {
		if n, ok := tool["web_search_requests"].(float64); ok {
			res.WebSearches = int(n)
		}
	}
	return res, err
}
