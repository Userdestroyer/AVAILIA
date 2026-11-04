package main

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"availia/gateway/internal/config"
	"availia/gateway/internal/laravel"
	"availia/gateway/internal/stream"

	"github.com/redis/go-redis/v9"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		slog.Error("config", "err", err)
		os.Exit(1)
	}

	rdb := redis.NewClient(&redis.Options{Addr: cfg.RedisAddr, Password: cfg.RedisPassword, DB: cfg.RedisDB})
	defer rdb.Close()

	upstream := http.DefaultTransport.(*http.Transport).Clone()
	// Заголовки ответа приходят быстро и в стрим-режиме; дальше тело может идти минутами
	upstream.ResponseHeaderTimeout = 2 * time.Minute

	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]string{"status": "ok"})
	})
	mux.Handle("GET /stream", &stream.Handler{
		Redis:     rdb,
		Providers: cfg.Providers,
		Laravel:   &laravel.Client{BaseURL: cfg.LaravelURL, Secret: cfg.Secret, HTTP: &http.Client{Timeout: 30 * time.Second}},
		Upstream:  &http.Client{Transport: upstream},
	})

	// WriteTimeout не ставим — он оборвал бы долгие стримы
	srv := &http.Server{
		Addr:              cfg.Addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	go func() {
		slog.Info("gateway listening", "addr", cfg.Addr)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			slog.Error("listen", "err", err)
			os.Exit(1)
		}
	}()

	<-ctx.Done()
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	srv.Shutdown(shutdownCtx)
}
