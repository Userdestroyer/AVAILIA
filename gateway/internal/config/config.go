package config

import (
	"errors"
	"os"
	"strconv"
)

// Provider — агрегатор нейросетей с OpenAI-совместимым API.
type Provider struct {
	BaseURL string
	APIKey  string
}

type Config struct {
	Addr          string
	RedisAddr     string
	RedisPassword string
	RedisDB       int
	// Общий секрет с Laravel для внутреннего эндпоинта
	Secret     string
	LaravelURL string
	// Ключи провайдеров живут только здесь: Laravel кладёт в Redis лишь тело запроса
	Providers map[string]Provider
}

func Load() (Config, error) {
	db, _ := strconv.Atoi(env("REDIS_DB", "2"))

	cfg := Config{
		Addr:          env("GATEWAY_ADDR", ":8080"),
		RedisAddr:     env("REDIS_ADDR", "redis:6379"),
		RedisPassword: os.Getenv("REDIS_PASSWORD"),
		RedisDB:       db,
		Secret:        os.Getenv("GATEWAY_SECRET"),
		LaravelURL:    env("LARAVEL_URL", "http://nginx"),
		Providers: map[string]Provider{
			"proxyapi": {
				BaseURL: env("PROXY_API_URL", "https://api.proxyapi.ru/v1"),
				APIKey:  os.Getenv("PROXY_API_KEY"),
			},
		},
	}

	if cfg.Secret == "" {
		return cfg, errors.New("GATEWAY_SECRET не задан")
	}

	return cfg, nil
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}
