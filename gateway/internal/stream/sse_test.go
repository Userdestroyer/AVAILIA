package stream

import (
	"encoding/json"
	"strings"
	"testing"
)

// sse собирает стрим из data-строк (как его отдаёт провайдер)
func sse(lines ...string) string {
	var b strings.Builder
	for _, line := range lines {
		b.WriteString("data: " + line + "\n\n")
	}
	return b.String()
}

func TestRead(t *testing.T) {
	tests := []struct {
		name        string
		format      string
		input       string
		content     string
		usage       map[string]float64 // ключевые поля usage, которые должны дойти до Laravel
		searches    []Search           // итоговый журнал поисков
		webSearches int
		kinds       string // последовательность kind'ов кусков через пробел
		wantError   string
	}{
		{
			name:   "chat: текст по кускам и usage в последнем чанке",
			format: FormatChat,
			input: sse(
				`{"choices":[{"delta":{"reasoning_content":"думаю"}}]}`,
				`{"choices":[{"delta":{"content":"При"}}]}`,
				`{"choices":[{"delta":{"content":"вет"}}]}`,
				`{"choices":[],"usage":{"prompt_tokens":10,"completion_tokens":2}}`,
				`[DONE]`,
			),
			content: "Привет",
			usage:   map[string]float64{"prompt_tokens": 10},
			kinds:   "reasoning delta delta",
		},
		{
			name:   "chat: ошибка провайдера посреди стрима",
			format: FormatChat,
			input: sse(
				`{"choices":[{"delta":{"content":"Нача"}}]}`,
				`{"error":{"message":"rate limit"}}`,
			),
			content:   "Нача",
			kinds:     "delta",
			wantError: "rate limit",
		},
		{
			name:   "responses: поиск GPT — запрос и открытие страницы, оплачен только запрос",
			format: FormatResponses,
			input: sse(
				`{"type":"response.output_item.added","item":{"id":"ws_1","type":"web_search_call","status":"in_progress"}}`,
				`{"type":"response.web_search_call.searching","item_id":"ws_1"}`,
				`{"type":"response.output_item.done","item":{"id":"ws_1","type":"web_search_call","status":"completed","action":{"type":"search","query":"курс доллара ЦБ"}}}`,
				`{"type":"response.output_item.done","item":{"id":"ws_2","type":"web_search_call","status":"completed","action":{"type":"open_page","url":"https://cbr.ru/"}}}`,
				`{"type":"response.output_text.delta","delta":"81,79 ₽"}`,
				`{"type":"response.completed","response":{"usage":{"input_tokens":11354,"input_tokens_details":{"cached_tokens":0},"output_tokens":250}}}`,
			),
			content:     "81,79 ₽",
			usage:       map[string]float64{"input_tokens": 11354, "output_tokens": 250},
			searches:    []Search{{ID: "ws_1", Status: "done", Query: "курс доллара ЦБ"}, {ID: "ws_2", Status: "done", Query: "https://cbr.ru/"}},
			webSearches: 1,
			kinds:       "search search search delta",
		},
		{
			name:   "responses: Gemini сообщает число поисков в usage, событий поиска нет",
			format: FormatResponses,
			input: sse(
				`{"type":"response.output_text.delta","delta":"Ответ"}`,
				`{"type":"response.completed","response":{"usage":{"input_tokens":26,"input_tokens_details":{"web_search_requests":1},"output_tokens":45}}}`,
			),
			content:     "Ответ",
			usage:       map[string]float64{"input_tokens": 26},
			webSearches: 1,
			kinds:       "delta",
		},
		{
			name:   "responses: упёрлись в лимит ответа — это ответ, а не ошибка",
			format: FormatResponses,
			input: sse(
				`{"type":"response.output_text.delta","delta":"Официальный курс до"}`,
				`{"type":"response.incomplete","response":{"usage":{"input_tokens":12691,"output_tokens":463}}}`,
			),
			content: "Официальный курс до",
			usage:   map[string]float64{"output_tokens": 463},
			kinds:   "delta",
		},
		{
			name:   "responses: провайдер упал",
			format: FormatResponses,
			input: sse(
				`{"type":"response.failed","response":{"error":{"message":"model overloaded"}}}`,
			),
			kinds:     "",
			wantError: "model overloaded",
		},
		{
			name:   "messages: Claude — запрос из input_json, результат, usage склеивается из start и delta",
			format: FormatMessages,
			input: sse(
				`{"type":"message_start","message":{"usage":{"input_tokens":14022,"cache_read_input_tokens":0,"output_tokens":1}}}`,
				`{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":""}}`,
				`{"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"надо поискать"}}`,
				`{"type":"content_block_start","index":1,"content_block":{"type":"server_tool_use","id":"srvtoolu_1","name":"web_search","input":{}}}`,
				`{"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\"query\": \"курс "}}`,
				`{"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"доллара\"}"}}`,
				`{"type":"content_block_stop","index":1}`,
				`{"type":"content_block_start","index":2,"content_block":{"type":"web_search_tool_result","tool_use_id":"srvtoolu_1","content":[{"type":"web_search_result","url":"https://cbr.ru/"}]}}`,
				`{"type":"content_block_start","index":3,"content_block":{"type":"text","text":""}}`,
				`{"type":"content_block_delta","index":3,"delta":{"type":"text_delta","text":"81,79 ₽"}}`,
				`{"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":456,"server_tool_use":{"web_search_requests":1}}}`,
				`{"type":"message_stop"}`,
			),
			content:     "81,79 ₽",
			usage:       map[string]float64{"input_tokens": 14022, "output_tokens": 456},
			searches:    []Search{{ID: "srvtoolu_1", Status: "done", Query: "курс доллара"}},
			webSearches: 1,
			kinds:       "reasoning search search search delta",
		},
		{
			name:   "messages: поиск не удался",
			format: FormatMessages,
			input: sse(
				`{"type":"content_block_start","index":0,"content_block":{"type":"server_tool_use","id":"srvtoolu_2","name":"web_search"}}`,
				`{"type":"content_block_start","index":1,"content_block":{"type":"web_search_tool_result","tool_use_id":"srvtoolu_2","content":{"type":"web_search_tool_result_error","error_code":"max_uses_exceeded"}}}`,
				`{"type":"message_stop"}`,
			),
			searches:    []Search{{ID: "srvtoolu_2", Status: "failed"}},
			webSearches: 1,
			kinds:       "search search",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			var kinds []string
			res, err := Read(tt.format, strings.NewReader(tt.input), func(c Chunk) { kinds = append(kinds, c.Kind) })

			if tt.wantError == "" && err != nil {
				t.Fatalf("неожиданная ошибка: %v", err)
			}
			if tt.wantError != "" && (err == nil || err.Error() != tt.wantError) {
				t.Fatalf("ошибка = %v, ждали %q", err, tt.wantError)
			}
			if res.Content != tt.content {
				t.Errorf("content = %q, ждали %q", res.Content, tt.content)
			}
			if got := strings.Join(kinds, " "); got != tt.kinds {
				t.Errorf("куски = %q, ждали %q", got, tt.kinds)
			}
			if res.WebSearches != tt.webSearches {
				t.Errorf("оплачено поисков = %d, ждали %d", res.WebSearches, tt.webSearches)
			}
			if len(res.Searches) != len(tt.searches) {
				t.Fatalf("поисков %d, ждали %d: %+v", len(res.Searches), len(tt.searches), res.Searches)
			}
			for i, s := range tt.searches {
				got := res.Searches[i]
				if got.ID != s.ID || got.Status != s.Status || got.Query != s.Query {
					t.Errorf("поиск %d = %+v, ждали %+v", i, got, s)
				}
			}
			if len(tt.usage) > 0 {
				var usage map[string]any
				if err := json.Unmarshal(res.Usage, &usage); err != nil {
					t.Fatalf("usage не JSON: %s", res.Usage)
				}
				for key, want := range tt.usage {
					if usage[key] != want {
						t.Errorf("usage[%s] = %v, ждали %v", key, usage[key], want)
					}
				}
			}
		})
	}
}
