package stream

import (
	"strings"
	"testing"
)

func TestReadChatStream(t *testing.T) {
	tests := []struct {
		name      string
		input     string
		content   string
		usage     string
		chunks    []Chunk
		wantError string
	}{
		{
			name: "текст по кускам и usage в последнем чанке",
			input: "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n\n" +
				"data: {\"choices\":[{\"delta\":{\"content\":\"При\"}}]}\n\n" +
				": ping\n\n" +
				"data: {\"choices\":[{\"delta\":{\"content\":\"вет\"}}]}\n\n" +
				"data: {\"choices\":[],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":2}}\n\n" +
				"data: [DONE]\n\n",
			content: "Привет",
			usage:   `{"prompt_tokens":10,"completion_tokens":2}`,
			chunks:  []Chunk{{"delta", "При"}, {"delta", "вет"}},
		},
		{
			name: "рассуждения в reasoning_content и reasoning строкой",
			input: "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"думаю\"}}]}\r\n\r\n" +
				"data: {\"choices\":[{\"delta\":{\"reasoning\":\" ещё\"}}]}\n\n" +
				"data: {\"choices\":[{\"delta\":{\"content\":\"Ответ\"}}],\"usage\":null}\n\n",
			content: "Ответ",
			chunks:  []Chunk{{"reasoning", "думаю"}, {"reasoning", " ещё"}, {"delta", "Ответ"}},
		},
		{
			name: "ошибка провайдера посреди стрима",
			input: "data: {\"choices\":[{\"delta\":{\"content\":\"Нача\"}}]}\n\n" +
				"data: {\"error\":{\"message\":\"rate limit\"}}\n\n",
			content:   "Нача",
			chunks:    []Chunk{{"delta", "Нача"}},
			wantError: "rate limit",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			var got []Chunk
			content, usage, err := ReadChatStream(strings.NewReader(tt.input), func(c Chunk) { got = append(got, c) })

			if tt.wantError == "" && err != nil {
				t.Fatalf("неожиданная ошибка: %v", err)
			}
			if tt.wantError != "" && (err == nil || err.Error() != tt.wantError) {
				t.Fatalf("ошибка = %v, ждали %q", err, tt.wantError)
			}
			if content != tt.content {
				t.Errorf("content = %q, ждали %q", content, tt.content)
			}
			if string(usage) != tt.usage {
				t.Errorf("usage = %s, ждали %s", usage, tt.usage)
			}
			if len(got) != len(tt.chunks) {
				t.Fatalf("чанков %d, ждали %d: %v", len(got), len(tt.chunks), got)
			}
			for i := range got {
				if got[i] != tt.chunks[i] {
					t.Errorf("чанк %d = %v, ждали %v", i, got[i], tt.chunks[i])
				}
			}
		})
	}
}
