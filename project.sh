#!/usr/bin/env bash
set -euo pipefail

# =====================================================================
# project.sh — управление проектом через docker compose
# Использование:
#   ./project.sh <команда> [аргументы...]
#   ENV=prod ./project.sh <команда>    # работать с prod-конфигом
# =====================================================================

# --- выбор compose-файла ---------------------------------------------
# prod-конфига пока нет — появится docker-compose.prod.yml, будет работать ENV=prod
ENV="${ENV:-dev}"
if [ "$ENV" = "prod" ]; then
    COMPOSE_FILE="docker-compose.prod.yml"
else
    COMPOSE_FILE="docker-compose.yml"
fi

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "ERROR: файл $COMPOSE_FILE не найден" >&2
    exit 1
fi

# Автодетекция: новый плагин "docker compose" или старый бинарь "docker-compose"
if docker compose version >/dev/null 2>&1; then
    DC="docker compose -f $COMPOSE_FILE"
else
    DC="docker-compose -f $COMPOSE_FILE"
fi

# Без TTY (скрипты, CI) exec нужен с -T
if [ -t 0 ]; then EXEC="$DC exec"; else EXEC="$DC exec -T"; fi

# --- хелперы ---------------------------------------------------------
usage() {
    cat <<EOF
Usage: ./project.sh <command> [args...]
       ENV=prod ./project.sh <command>     (для prod-окружения)

Commands:
  up                  Поднять стек (с пересборкой)
  down                Остановить стек (без удаления volume!)
  restart             Перезапустить все сервисы
  status              Показать статус контейнеров
  logs [service]      Хвост логов (все или конкретный сервис)
  artisan <args...>   php artisan ...
  composer <args...>  composer ...
  test [args...]      Запуск php artisan test
  go <args...>        go ... в контейнере gateway (go get, go test ./... и т.д.)
  shell [service]     Войти в bash контейнера (по умолчанию php)
  install             Первичная установка (composer, npm, миграции, справочники, курсы, суперюзер)
  update              Обновление (deps + migrate + справочники + cache + перезапуск воркера)
  migrate             Запустить миграции
  fresh               Снести БД и накатить миграции + сиды (ОПАСНО)
  build               Пересобрать образы без запуска
  prune               Удалить dangling images + volumes без метки keep=true
  <service> <args>    docker compose run --rm <service> <args>
EOF
}

need_arg() {
    if [ $# -eq 0 ]; then
        echo "ERROR: команда требует аргументов" >&2
        usage
        exit 1
    fi
}

# --- основной диспетчер ----------------------------------------------
if [ $# -eq 0 ]; then
    usage
    exit 0
fi

CMD="$1"
shift

case "$CMD" in
    up)
        $DC up -d --build
        ;;

    down)
        # Внимание: volume НЕ удаляем. Если нужно — делай руками.
        $DC down
        ;;

    restart)
        $DC restart "$@"
        ;;

    status|ps)
        $DC ps
        ;;

    logs)
        $DC logs -f --tail=100 "$@"
        ;;

    artisan)
        $EXEC php php artisan "$@"
        ;;

    composer)
        $EXEC php composer "$@"
        ;;

    test)
        # при необходимости подменить хост БД — раскомментируй и подправь
        # $EXEC -e DB_HOST=postgres_test php php artisan test "$@"
        $EXEC php php artisan test "$@"
        ;;

    go)
        $EXEC gateway go "$@"
        ;;

    shell)
        SERVICE="${1:-php}"
        $EXEC "$SERVICE" sh -c 'command -v bash >/dev/null && exec bash || exec sh'
        ;;

    migrate)
        if [ "$ENV" = "prod" ]; then
            $EXEC php php artisan migrate --force
        else
            $EXEC php php artisan migrate
        fi
        ;;

    fresh)
        if [ "$ENV" = "prod" ]; then
            echo "ERROR: 'fresh' запрещён в prod" >&2
            exit 1
        fi
        $EXEC php php artisan migrate:fresh --seed
        ;;

    install)
        if [ "$ENV" = "prod" ]; then
            $EXEC php composer install --no-dev --optimize-autoloader
            $DC run --rm npm ci
            $DC run --rm npm run build
            $EXEC php php artisan availia:install
            $EXEC php php artisan config:cache
            $EXEC php php artisan route:cache
            $EXEC php php artisan view:cache
        else
            $DC run --rm gateway go mod download
            $EXEC php composer install
            $DC run --rm npm install
            $DC run --rm npm run build
            $EXEC php php artisan availia:install
            $EXEC php php artisan config:clear
            $EXEC php php artisan route:clear
            $EXEC php php artisan view:clear
        fi
        ;;

    update)
        if [ "$ENV" = "prod" ]; then
            $EXEC php composer install --no-dev --optimize-autoloader
            $DC run --rm npm ci
            $DC run --rm npm run build
            $EXEC php php artisan migrate --force
            # Справочники идемпотентны — новые модели, возможности и т.п. доезжают при каждом обновлении
            $EXEC php php artisan db:seed --force
            $EXEC php php artisan config:cache
            $EXEC php php artisan route:cache
            $EXEC php php artisan view:cache
            # Воркер держит старый код в памяти — перезапускаем
            $EXEC php php artisan queue:restart
        else
            $DC run --rm gateway go mod download
            $EXEC php composer install
            $DC run --rm npm install
            $DC run --rm npm run build
            $EXEC php php artisan migrate
            $EXEC php php artisan db:seed
            $EXEC php php artisan config:clear
            $EXEC php php artisan route:clear
            $EXEC php php artisan view:clear
        fi
        ;;

    build)
        $DC build "$@"
        ;;

    prune)
        # Безопасный prune — dangling images + volumes без метки keep=true
        docker image prune -f
        docker volume ls -q --filter dangling=true | while read -r vol; do
            label=$(docker volume inspect --format '{{ index .Labels "keep" }}' "$vol" 2>/dev/null)
            if [ "$label" != "true" ]; then
                docker volume rm "$vol"
            fi
        done
        ;;

    help|-h|--help)
        usage
        ;;

    *)
        # Fallback: попытаться запустить одноразовый сервис
        $DC run --rm "$CMD" "$@"
        ;;
esac