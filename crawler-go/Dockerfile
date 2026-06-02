# Multi-stage build for the Go crawl worker (crawler-go).
# Stage 1: compile a static binary. Stage 2: minimal runtime with CA certs.
FROM golang:1.25-alpine AS build
WORKDIR /src
RUN apk add --no-cache git
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" \
    -o /out/scouter-crawler ./cmd/scouter-crawler

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata && adduser -D -u 10001 scouter
COPY --from=build /out/scouter-crawler /usr/local/bin/scouter-crawler
USER scouter
ENTRYPOINT ["/usr/local/bin/scouter-crawler"]
