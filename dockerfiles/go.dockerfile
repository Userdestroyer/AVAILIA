FROM golang:1.27-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache git \
 && addgroup -g ${GID} app \
 && adduser -G app -D -s /bin/sh -u ${UID} app

# air — горячая перезагрузка в dev. Ставим в /usr/local/bin, чтобы volume на /go его не перекрыл
RUN GOBIN=/usr/local/bin go install github.com/air-verse/air@v1.67.4

# Кэши модулей и сборки — в volume go_cache (/go)
ENV GOCACHE=/go/.cache/go-build \
    GOFLAGS=-buildvcs=false

WORKDIR /app

USER app

CMD ["air", "-c", ".air.toml"]
