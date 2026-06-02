#!/bin/bash
# Go↔PHP categorization parity test.
# Spins a throwaway Postgres, loads the schema, then runs the Go white-box test
# (which seeds data, categorizes one crawl in Go + one in PHP, and compares).
#
# Requires: docker, php (cli + pdo_pgsql), a Go toolchain on PATH.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PG=scouter_parity_pg
PORT=55433

cleanup() { docker rm -f "$PG" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "[1/4] Starting throwaway Postgres on :$PORT…"
docker rm -f "$PG" >/dev/null 2>&1 || true
docker run -d --name "$PG" -e POSTGRES_PASSWORD=test -e POSTGRES_DB=scouter \
  -e POSTGRES_USER=scouter -p "$PORT:5432" postgres:16-alpine >/dev/null
for i in $(seq 1 30); do
  docker exec "$PG" pg_isready -U scouter >/dev/null 2>&1 && break; sleep 1
done

echo "[2/4] Loading schema…"
docker cp "$ROOT/docker/postgres/init.sql" "$PG:/tmp/init.sql" >/dev/null
docker exec "$PG" psql -U scouter -d scouter -q -f /tmp/init.sql >/dev/null 2>&1

echo "[3/4] Running parity test…"
export PARITY_DATABASE_URL="postgresql://scouter:test@127.0.0.1:$PORT/scouter"
export PARITY_PDO_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=scouter"
export PARITY_PDO_USER="scouter"
export PARITY_PDO_PASS="test"

cd "$ROOT/crawler-go"
go test ./internal/postprocess/ -run TestCategorizationParity -v -count=1

echo "[4/4] Done."
