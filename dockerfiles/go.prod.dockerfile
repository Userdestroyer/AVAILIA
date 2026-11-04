# Прод-сборка гейтвея: компилируем статический бинарник и кладём его в пустой alpine.
# В dev вместо этого go.dockerfile с горячей перезагрузкой (air).
FROM golang:1.27-alpine AS build

WORKDIR /src
COPY gateway/go.mod gateway/go.sum ./
RUN go mod download

COPY gateway/ ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /gateway ./cmd/gateway

FROM alpine:3.24

RUN apk add --no-cache ca-certificates \
 && adduser -D -u 1000 app

COPY --from=build /gateway /usr/local/bin/gateway

USER app

CMD ["gateway"]
