package laravel

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

// Result — итог стрима, который сохраняет Laravel (и считает по нему стоимость).
type Result struct {
	Status  string          `json:"status"` // "done" | "failed"
	Format  string          `json:"format,omitempty"`
	Content string          `json:"content"`
	Usage   json.RawMessage `json:"usage,omitempty"`
	// Сколько поисков оплачено и их журнал (для пузырьков после перезагрузки)
	WebSearches int    `json:"web_searches"`
	Searches    any    `json:"searches,omitempty"`
	Error       string `json:"error,omitempty"`
}

type Client struct {
	BaseURL string
	Secret  string
	HTTP    *http.Client
}

// Complete отчитывается о готовом ответе и возвращает сохранённое сообщение (с ценой) для браузера.
// Повторяет попытку: потерять оплаченный ответ из-за секундной недоступности Laravel обидно.
func (c *Client) Complete(ctx context.Context, messageID int64, result Result) (json.RawMessage, error) {
	body, err := json.Marshal(result)
	if err != nil {
		return nil, err
	}

	url := fmt.Sprintf("%s/internal/gateway/messages/%d/complete", c.BaseURL, messageID)
	var lastErr error

	for attempt := range 3 {
		if attempt > 0 {
			time.Sleep(time.Duration(attempt) * 500 * time.Millisecond)
		}

		message, err := c.post(ctx, url, body)
		if err == nil {
			return message, nil
		}
		lastErr = err
	}

	return nil, lastErr
}

func (c *Client) post(ctx context.Context, url string, body []byte) (json.RawMessage, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Gateway-Secret", c.Secret)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	data, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("laravel ответил %d: %.300s", resp.StatusCode, data)
	}

	var payload struct {
		Message json.RawMessage `json:"message"`
	}
	if err := json.Unmarshal(data, &payload); err != nil {
		return nil, err
	}

	return payload.Message, nil
}
