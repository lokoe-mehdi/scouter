#!/bin/bash

# 1. Detect Docker Compose command
if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE="docker compose"
elif docker-compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE="docker-compose"
else
    DOCKER_COMPOSE=""
fi

# 2. Auto-install Docker if missing
if ! command -v docker &> /dev/null; then
    echo "⚠️ Docker is not installed. Attempting automatic installation..."
    # Uses the official Docker install script (supports Debian, Ubuntu, CentOS, Fedora)
    curl -fsSL https://get.docker.com -o get-docker.sh
    sudo sh get-docker.sh
    sudo usermod -aG docker $USER
    rm get-docker.sh
    echo "✅ Docker installed. Note: You may need to restart your session to use Docker without sudo."

    # If Docker was just installed, set the default command
    DOCKER_COMPOSE="docker compose"
fi

# 3. Final check for the Compose plugin
if [ -z "$DOCKER_COMPOSE" ]; then
    echo "❌ Docker is present but Compose is missing. Installing plugin..."
    sudo apt-get update && sudo apt-get install -y docker-compose-plugin
    DOCKER_COMPOSE="docker compose"
fi

echo "================================"
echo "   Scouter - Starting ($DOCKER_COMPOSE)"
echo "================================"

# Using the $DOCKER_COMPOSE variable everywhere
echo "[1/3] Stopping containers..."
$DOCKER_COMPOSE -f docker-compose.local.yml down

echo "[2/3] Building image..."
$DOCKER_COMPOSE -f docker-compose.local.yml build

echo "[3/3] Starting..."
$DOCKER_COMPOSE -f docker-compose.local.yml up -d --scale worker=4 --remove-orphans

echo "Web access: http://localhost:8080"
$DOCKER_COMPOSE -f docker-compose.local.yml logs -f
