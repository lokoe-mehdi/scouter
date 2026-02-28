# Build stage
FROM golang:1.21-alpine AS builder

WORKDIR /app

# Installer les dépendances de build
RUN apk add --no-cache git

# Copier les fichiers Go et télécharger les dépendances
COPY go.mod main.go ./
RUN go mod tidy && CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o renderer .

# Runtime stage - Debian avec Chromium
FROM debian:bookworm-slim

WORKDIR /app

# Installer Chromium et dépendances
RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    ca-certificates \
    fonts-liberation \
    libnss3 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libxkbcommon0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libasound2 \
    && rm -rf /var/lib/apt/lists/*

# Copier le binaire
COPY --from=builder /app/renderer /app/renderer

EXPOSE 3000

# Rod utilisera Chromium
ENV ROD_BROWSER=/usr/bin/chromium

CMD ["/app/renderer"]