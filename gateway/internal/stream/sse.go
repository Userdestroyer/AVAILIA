package stream

import (
	"bufio"
	"encoding/json"
	"errors"
	"io"
	"strings"
)

// Chunk — кусок ответа для браузера: текст ответа или рассуждения модели.
type Chunk struct {
	Kind string // "delta" | "reasoning"
	Text string
}

type completionChunk struct {
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
}

// ReadChatStream разбирает SSE-ответ OpenAI-совместимого chat/completions (stream: true).
// На каждый кусок текста вызывает emit; возвращает весь ответ и последний непустой usage.
func ReadChatStream(r io.Reader, emit func(Chunk)) (string, json.RawMessage, error) {
	reader := bufio.NewReader(r)
	var content strings.Builder
	var usage json.RawMessage

	for {
		line, readErr := reader.ReadString('\n')
		line = strings.TrimRight(line, "\r\n")

		if payload, ok := strings.CutPrefix(line, "data:"); ok {
			payload = strings.TrimSpace(payload)
			if payload == "[DONE]" {
				return content.String(), usage, nil
			}

			var chunk completionChunk
			if err := json.Unmarshal([]byte(payload), &chunk); err == nil {
				if chunk.Error != nil {
					return content.String(), usage, errors.New(chunk.Error.Message)
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
					usage = chunk.Usage
				}
			}
		}

		if readErr != nil {
			if errors.Is(readErr, io.EOF) {
				// Поток закрылся без [DONE] — отдаём что успели собрать
				return content.String(), usage, nil
			}
			return content.String(), usage, readErr
		}
	}
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
