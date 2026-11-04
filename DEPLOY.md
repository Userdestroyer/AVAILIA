# Деплой AVAILIA на VPS userdestroyer

AVAILIA живёт на том же сервере, что и userdestroyer, по адресу `availia.userdestroyer.com`.

```
интернет ──443──▶ nginx userdestroyer ──(docker-сеть edge)──▶ nginx AVAILIA ──▶ php / gateway
                  (TLS, certbot)          имя availia-nginx
```

Порты 80/443 держит nginx userdestroyer — он же выпускает сертификат и по имени хоста
отдаёт запросы AVAILIA. Сама AVAILIA наружу портов не открывает.

## 1. DNS

A-запись `availia.userdestroyer.com` → IP VPS (или CNAME на `userdestroyer.com`).
Проверка: `dig +short availia.userdestroyer.com`.

## 2. Общая docker-сеть (один раз)

```bash
docker network create edge
```

## 3. Userdestroyer: подключить nginx к сети edge

`docker-compose.prod.yml` userdestroyer:

```yaml
networks:
  laravel:
  edge:
    external: true

services:
  nginx:
    # ...как было...
    networks:
      - laravel
      - edge
```

## 4. Userdestroyer: блок для 80-го порта

В `dockerfiles/nginx/prod.conf` userdestroyer **до** существующих server-блоков:

```nginx
# ── AVAILIA, 80: проверка certbot + редирект на https ───────────────────
server {
    listen 80;
    server_name availia.userdestroyer.com;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html/public;
    }

    location / {
        return 301 https://availia.userdestroyer.com$request_uri;
    }
}
```

Без него `http://availia…` улетит в общий редирект на `userdestroyer.com`.
Перезапуск: `ENV=prod ./project.sh restart nginx` (в папке userdestroyer).

## 5. Сертификат

В папке userdestroyer (certbot тот же, продлевать будет сам вместе с основным):

```bash
docker compose -f docker-compose.prod.yml run --rm --entrypoint certbot certbot \
    certonly --webroot -w /var/www/html/public \
    -d availia.userdestroyer.com --email you@example.com --agree-tos --no-eff-email
```

## 6. Userdestroyer: блок для 443

Только **после** выпуска сертификата (иначе nginx не стартует без файлов сертификата):

```nginx
# Для WebSocket в будущем: Connection: upgrade только если клиент его просил
map $http_upgrade $availia_connection_upgrade {
    default upgrade;
    ''      '';
}

# ── AVAILIA, 443 ─────────────────────────────────────────────────────────
server {
    listen 443 ssl;
    http2 on;
    server_name availia.userdestroyer.com;

    ssl_certificate     /etc/letsencrypt/live/availia.userdestroyer.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/availia.userdestroyer.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    client_max_body_size 64M;

    # Имя AVAILIA резолвится в момент запроса, а не при старте nginx:
    # если AVAILIA лежит, userdestroyer всё равно поднимется
    resolver 127.0.0.11 valid=30s;
    set $availia http://availia-nginx:80;

    location / {
        proxy_pass $availia;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port 443;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $availia_connection_upgrade;

        # Стрим ответов (SSE, /gw/): без буферизации, генерация может идти минутами
        proxy_buffering off;
        proxy_read_timeout 600s;
        proxy_send_timeout 600s;
    }
}
```

`ENV=prod ./project.sh restart nginx` в папке userdestroyer.

## 7. AVAILIA: окружение

Склонировать репозиторий рядом с userdestroyer, затем:

```bash
cp .env.example .env
cp src/.env.example src/.env
```

**`.env` (корень)** — для docker compose:

| Переменная | Значение |
|---|---|
| `COMPOSE_PROJECT_NAME` | `availia` — чтобы контейнеры и volume не пересеклись с userdestroyer |
| `HOST_UID` / `HOST_GID` | `id -u` / `id -g` юзера, от которого деплоишь |
| `FORWARD_DB_PORT` | `5433` — 5432 занят postgres userdestroyer |
| `DB_PASSWORD`, `REDIS_PASSWORD` | новые: `openssl rand -hex 16` |
| `GATEWAY_SECRET` | новый: `openssl rand -hex 32` |
| `PROXY_API_KEY` | ключ proxyapi |

**`src/.env`** — для Laravel:

| Переменная | Значение |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://availia.userdestroyer.com` |
| `APP_KEY` | пусто — сгенерирует `install` |
| `DB_HOST` / `DB_PORT` | `postgres` / `5432` (внутри docker-сети порт обычный) |
| `DB_PASSWORD`, `REDIS_PASSWORD`, `GATEWAY_SECRET`, `PROXY_API_KEY` | **те же**, что в корневом `.env` |
| `TRUSTED_PROXIES` | `*` — AVAILIA за двумя nginx; без этого Laravel думает, что сайт на http, а лимит попыток входа считает всех с одного IP |
| `SESSION_SECURE_COOKIE` | `true` |
| `LOG_LEVEL` | `warning` |

## 8. AVAILIA: запуск

```bash
ENV=prod ./project.sh up        # собрать образы и поднять стек
ENV=prod ./project.sh install   # зависимости, фронт, миграции, справочники, курсы, суперюзер
```

`install` спросит почту, логин и пароль суперюзера.

Проверка: `https://availia.userdestroyer.com` → страница входа; после входа — отправить сообщение
дешёвой модели (Claude Haiku 5.5).

## Обновление

```bash
git pull
ENV=prod ./project.sh update    # зависимости, фронт, миграции, кэши конфига/роутов/вью
ENV=prod ./project.sh up        # пересобрать образы — нужно, если менялся Go-гейтвей или Dockerfile
```

После правки `src/.env` на проде: `ENV=prod ./project.sh artisan config:cache`.

## Что стоит проверить на VPS

- **Память**: рядом с userdestroyer встанут ещё postgres, redis, php-fpm, nginx и гейтвей —
  ориентировочно +400–600 МБ. `free -h` до и после.
- **ЦБ и proxyapi** доступны с российского IP — курсы валют пойдут с ЦБ (с запасным источником).
