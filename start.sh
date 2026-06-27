#!/bin/bash
set -e

# 1. Require Docker. Do not install privileged software from this project script.
if ! command -v docker &> /dev/null; then
    echo "Docker is not installed."
    echo "Install Docker or Podman manually, then rerun ./start.sh."
    echo "Docker docs: https://docs.docker.com/engine/install/"
    exit 1
fi

# 2. Detect Docker Compose command
if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE="docker compose"
elif docker-compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE="docker-compose"
else
    DOCKER_COMPOSE=""
fi

# 3. Require Compose. Do not run sudo package managers from this project script.
if [ -z "$DOCKER_COMPOSE" ]; then
    echo "Docker is present but Docker Compose is missing."
    echo "Install the Compose plugin manually, then rerun ./start.sh."
    echo "Compose docs: https://docs.docker.com/compose/install/"
    exit 1
fi

echo "================================"
echo "   Scouter - Starting ($DOCKER_COMPOSE)"
echo "================================"

# [0] Auto-size container limits to THIS machine (CPU + RAM). No hand-tuning:
# change the hardware, re-run start.sh, the limits recompute. The generated
# file is sourced into the environment so compose interpolates the ${*_MEM_LIMIT}
# and concurrency vars. See scripts/autosize.sh.
echo "[0/3] Auto-sizing to host..."
bash scripts/autosize.sh .env.autosize
set -a; . ./.env.autosize; set +a

# Using the $DOCKER_COMPOSE variable everywhere
echo "[1/3] Stopping containers..."
$DOCKER_COMPOSE -f docker-compose.local.yml down

echo "[2/3] Building image..."
$DOCKER_COMPOSE -f docker-compose.local.yml build

echo "[3/3] Starting..."
$DOCKER_COMPOSE -f docker-compose.local.yml up -d --scale worker=4 --remove-orphans

echo "Web access: http://localhost:8080"
$DOCKER_COMPOSE -f docker-compose.local.yml logs -f
