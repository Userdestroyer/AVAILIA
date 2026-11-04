package stream

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"availia/gateway/internal/config"
	"availia/gateway/internal/laravel"

	"github.com/redis/go-redis/v9"
)

const (
	// Сколько максимум ждём генерацию целиком
	generationTimeout = 10 * time.Minute
	// Пинг держит соединение живым, пока модель думает и молчит
	pingInterval = 15 * time.Second
)

// Задание, которое Laravel положил в Redis под одноразовым токеном.
type job struct {
	Provider  string          `json:"provider"`
	MessageID int64           `json:"message_id"`
	Body      json.RawMessage `json:"body"`
}

type event struct {
	Name string
	Data any
}

type Handler struct {
	Redis     *redis.Client
	Providers map[string]config.Provider
	Laravel   *laravel.Client
	// Клиент к провайдерам — без общего таймаута, стрим может идти минутами
	Upstream *http.Client
}

// ServeHTTP: GET /stream?token=… — отдаёт браузеру ответ модели по SSE.
func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	token := r.URL.Query().Get("token")
	if token == "" {
		http.Error(w, "token required", http.StatusBadRequest)
		return
	}

	// GETDEL — токен одноразовый: второй раз этот же стрим не запустить
	raw, err := h.Redis.GetDel(r.Context(), "stream:"+token).Bytes()
	if errors.Is(err, redis.Nil) {
		http.Error(w, "stream not found", http.StatusNotFound)
		return
	}
	if err != nil {
		slog.Error("redis", "err", err)
		http.Error(w, "internal error", http.StatusInternalServerError)
		return
	}

	var j job
	if err := json.Unmarshal(raw, &j); err != nil {
		http.Error(w, "bad job", http.StatusInternalServerError)
		return
	}
	provider, ok := h.Providers[j.Provider]
	if !ok || provider.APIKey == "" {
		slog.Error("provider not configured", "provider", j.Provider)
		http.Error(w, "provider not configured", http.StatusInternalServerError)
		return
	}

	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "streaming unsupported", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)
	flusher.Flush()

	// Генерация отвязана от соединения: закрыл вкладку — ответ всё равно допишется и сохранится
	events := make(chan event)
	gone := make(chan struct{})
	defer close(gone)
	go h.generate(j, provider, events, gone)

	ping := time.NewTicker(pingInterval)
	defer ping.Stop()

	for {
		select {
		case ev, open := <-events:
			if !open {
				return
			}
			if err := writeEvent(w, ev); err != nil {
				return
			}
			flusher.Flush()
		case <-ping.C:
			if _, err := io.WriteString(w, ": ping\n\n"); err != nil {
				return
			}
			flusher.Flush()
		case <-r.Context().Done():
			return
		}
	}
}

func (h *Handler) generate(j job, provider config.Provider, events chan<- event, gone <-chan struct{}) {
	defer close(events)

	// После ухода клиента отправка в браузер просто пропускается
	send := func(ev event) {
		select {
		case events <- ev:
		case <-gone:
		}
	}

	ctx, cancel := context.WithTimeout(context.Background(), generationTimeout)
	defer cancel()

	content, usage, err := h.callProvider(ctx, j, provider, func(c Chunk) {
		send(event{Name: c.Kind, Data: map[string]string{"text": c.Text}})
	})

	result := laravel.Result{Status: "done", Content: content, Usage: usage}
	if err != nil {
		slog.Warn("generation failed", "message_id", j.MessageID, "err", err)
		result.Status = "failed"
		result.Error = err.Error()
	}

	message, err := h.Laravel.Complete(ctx, j.MessageID, result)
	if err != nil {
		slog.Error("laravel complete failed", "message_id", j.MessageID, "err", err)
		send(event{Name: "fail", Data: map[string]string{"message": "Ответ не удалось сохранить"}})
		return
	}

	send(event{Name: "done", Data: map[string]json.RawMessage{"message": message}})
}

func (h *Handler) callProvider(ctx context.Context, j job, provider config.Provider, emit func(Chunk)) (string, json.RawMessage, error) {
	url := strings.TrimRight(provider.BaseURL, "/") + "/chat/completions"
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(j.Body))
	if err != nil {
		return "", nil, err
	}
	req.Header.Set("Authorization", "Bearer "+provider.APIKey)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "text/event-stream")

	resp, err := h.Upstream.Do(req)
	if err != nil {
		return "", nil, fmt.Errorf("провайдер недоступен: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		return "", nil, fmt.Errorf("провайдер ответил %d: %s", resp.StatusCode, errorMessage(body))
	}

	return ReadChatStream(resp.Body, emit)
}

// errorMessage достаёт текст ошибки из OpenAI-формата {"error":{"message":…}}.
func errorMessage(body []byte) string {
	var payload struct {
		Error struct {
			Message string `json:"message"`
		} `json:"error"`
	}
	if json.Unmarshal(body, &payload) == nil && payload.Error.Message != "" {
		return payload.Error.Message
	}
	return strings.TrimSpace(fmt.Sprintf("%.300s", body))
}

func writeEvent(w io.Writer, ev event) error {
	data, err := json.Marshal(ev.Data)
	if err != nil {
		return err
	}
	_, err = fmt.Fprintf(w, "event: %s\ndata: %s\n\n", ev.Name, data)
	return err
}
