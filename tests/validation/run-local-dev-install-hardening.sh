#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

LOG_DIR="$ROOT/tests/validation"
LOG_FILE="$LOG_DIR/local-dev-install-hardening.$(date +%Y%m%d-%H%M%S).log"
COMPOSE=(docker compose -f docker-compose.local.yml)

FRESH=0
YES=0

usage() {
  cat <<'EOF'
Usage: tests/validation/run-local-dev-install-hardening.sh [--fresh] [--yes]

Runs the local dev install + hardening validation.

Options:
  --fresh  Run a destructive fresh-install check first:
           docker compose -f docker-compose.local.yml down -v --remove-orphans
  --yes    Do not prompt before the destructive --fresh reset.
  --help   Show this help.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --fresh) FRESH=1 ;;
    --yes) YES=1 ;;
    --help|-h) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

log() {
  printf '\n==> %s\n' "$*" | tee -a "$LOG_FILE"
}

capture() {
  log "$*"
  "$@" 2>&1 | tee -a "$LOG_FILE"
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    printf 'Missing required command: %s\n' "$1" >&2
    exit 127
  fi
}

container_id() {
  local service="$1"
  local cid labels

  cid="$("${COMPOSE[@]}" ps -q "$service" 2>/dev/null | head -n 1 || true)"
  if [[ -n "$cid" ]]; then
    printf '%s\n' "$cid"
    return 0
  fi

  # podman-compose does not support `ps -q SERVICE`, so fall back to Compose labels.
  while IFS= read -r cid; do
    [[ -z "$cid" ]] && continue
    labels="$(docker inspect "$cid" --format '{{ index .Config.Labels "com.docker.compose.service" }} {{ index .Config.Labels "io.podman.compose.service" }}' 2>/dev/null || true)"
    if [[ " $labels " == *" $service "* ]]; then
      printf '%s\n' "$cid"
      return 0
    fi
  done < <("${COMPOSE[@]}" ps -q 2>/dev/null)
}

compose_exec() {
  local service="$1"
  shift
  local cid
  cid="$(container_id "$service")"
  if [[ -z "$cid" ]]; then
    printf 'No running container found for service: %s\n' "$service" >&2
    return 1
  fi
  docker exec "$cid" "$@"
}

wait_until() {
  local label="$1"
  local timeout="$2"
  shift 2

  log "Waiting for ${label} (timeout ${timeout}s)"
  local end=$((SECONDS + timeout))
  until "$@" >/dev/null 2>&1; do
    if (( SECONDS >= end )); then
      printf 'Timed out waiting for %s\n' "$label" >&2
      return 1
    fi
    sleep 2
  done
}

postgres_ready() {
  compose_exec postgres pg_isready -U scouter -d scouter
}

clickhouse_ready() {
  compose_exec clickhouse clickhouse-client -q 'SELECT 1'
}

http_ready() {
  compose_exec scouter curl -fsS -o /dev/null http://127.0.0.1:8080/login.php
}

jobs_schema_ready() {
  local count
  count="$(compose_exec postgres psql -U scouter -d scouter -tAc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('jobs','job_logs');")"
  [[ "$count" == "2" ]]
}

require_cmd composer
require_cmd php
require_cmd bash
require_cmd curl
require_cmd docker

mkdir -p "$LOG_DIR"
printf '# Local dev install hardening validation\nStarted: %s\nFresh install: %s\n\n' \
  "$(date -Iseconds)" "$FRESH" > "$LOG_FILE"

if [[ "$FRESH" -eq 1 ]]; then
  if [[ "$YES" -ne 1 ]]; then
    printf 'This will delete Compose containers and volumes for this project (down -v). Continue? [y/N] ' >&2
    read -r answer
    case "$answer" in
      y|Y|yes|YES) ;;
      *) printf 'Aborted.\n' >&2; exit 130 ;;
    esac
  fi
  capture "${COMPOSE[@]}" down -v --remove-orphans
fi

capture composer validate --strict
capture composer install --dry-run --no-interaction --prefer-dist --optimize-autoloader
capture composer audit --locked
capture php -l web/diagnostic.php
capture php -l scripts/create-demo-user.php
capture bash -n start.sh
capture git diff --check

capture "${COMPOSE[@]}" config
capture "${COMPOSE[@]}" build scouter worker
capture "${COMPOSE[@]}" up -d --force-recreate --scale worker=4 --remove-orphans

wait_until "PostgreSQL" 120 postgres_ready
wait_until "ClickHouse" 120 clickhouse_ready
wait_until "Scouter HTTP" 180 http_ready
wait_until "jobs/job_logs schema" 120 jobs_schema_ready

capture "${COMPOSE[@]}" ps

capture curl -sS -I http://localhost:8080

capture compose_exec postgres psql -U scouter -d scouter -c '\dt job*'

log "Checking diagnostic.php over host HTTP when port forwarding is available"
host_diag_status="$(curl -sS -o /dev/null -w '%{http_code}' http://localhost:8080/diagnostic.php || true)"
printf 'host diagnostic.php HTTP status: %s\n' "${host_diag_status:-curl-failed}" | tee -a "$LOG_FILE"
if [[ "$host_diag_status" != "404" ]]; then
  printf 'Host diagnostic.php check did not return 404; falling back to in-container HTTP check.\n' | tee -a "$LOG_FILE"
fi

log "Checking diagnostic.php returns 404 over HTTP inside the scouter container"
diag_status="$(compose_exec scouter curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/diagnostic.php)"
printf 'container diagnostic.php HTTP status: %s\n' "$diag_status" | tee -a "$LOG_FILE"
if [[ "$diag_status" != "404" ]]; then
  printf 'Expected diagnostic.php to return 404 over HTTP, got %s\n' "$diag_status" >&2
  exit 1
fi

capture compose_exec scouter php /app/web/diagnostic.php

log "Checking create-demo-user.php refuses by default"
set +e
demo_output="$(compose_exec scouter php /app/scripts/create-demo-user.php 2>&1)"
demo_code=$?
set -e
printf '%s\n' "$demo_output" | tee -a "$LOG_FILE"
if [[ "$demo_code" -eq 0 ]]; then
  printf 'Expected create-demo-user.php to fail by default, but it exited 0\n' >&2
  exit 1
fi
if [[ "$demo_output" != *"Refusing to create demo admin"* ]]; then
  printf 'Expected create-demo-user.php refusal message was not found\n' >&2
  exit 1
fi

log "Checking demo account was not created"
demo_count="$(compose_exec postgres psql -U scouter -d scouter -tAc "SELECT count(*) FROM users WHERE email = 'demo@scouter.local';")"
printf 'demo@scouter.local count: %s\n' "$demo_count" | tee -a "$LOG_FILE"
if [[ "$demo_count" != "0" ]]; then
  printf 'Expected no demo@scouter.local user, got count %s\n' "$demo_count" >&2
  exit 1
fi

capture compose_exec scouter ./vendor/bin/pest
capture "${COMPOSE[@]}" logs --tail=120 scouter worker crawler-go postgres clickhouse
capture "${COMPOSE[@]}" ps

printf '\nValidation completed successfully: %s\nLog: %s\n' "$(date -Iseconds)" "$LOG_FILE" | tee -a "$LOG_FILE"
