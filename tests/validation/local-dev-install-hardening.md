# Local Dev Install and Hardening Validation

Date: 2026-06-23T14:11:51+02:00

Branch: `fix/local-dev-install`

## Scope

This validation covers the local development install fixes and hardening changes:

- Rootless Podman / SELinux bind mount labels in `docker-compose.local.yml`.
- Fresh install baseline schema for `jobs` and `job_logs`.
- `composer.lock` based Docker build using `composer install`.
- CLI-only `web/diagnostic.php`.
- Opt-in-only `scripts/create-demo-user.php`.
- Removal of privileged auto-install behavior from `start.sh`.

## Commands and Results

| Check | Command | Result |
| --- | --- | --- |
| Composer manifest | `composer validate --strict` | Pass: `./composer.json is valid` |
| Composer locked install plan | `composer install --dry-run --no-interaction --prefer-dist --optimize-autoloader` | Pass: lock file installable; 69 installs planned |
| Composer advisories | `composer audit --locked` | Pass: no security vulnerability advisories found |
| PHP lint, diagnostic | `php -l web/diagnostic.php` | Pass: no syntax errors |
| PHP lint, demo user script | `php -l scripts/create-demo-user.php` | Pass: no syntax errors |
| Shell syntax | `bash -n start.sh` | Pass |
| Whitespace check | `git diff --check` | Pass |
| Compose config | `docker compose -f docker-compose.local.yml config` | Pass |
| PHP image build | `docker compose -f docker-compose.local.yml build scouter worker` | Pass: Composer installed from `composer.lock` |
| Fresh stack reset | `tests/validation/run-local-dev-install-hardening.sh --fresh --yes` | Pass: Compose containers and volumes dropped, stack rebuilt from empty volumes |
| Stack startup | `docker compose -f docker-compose.local.yml up -d --force-recreate --scale worker=4 --remove-orphans` | Pass |
| HTTP app entrypoint | `curl -sS -I http://localhost:8080` | Pass: `HTTP/1.1 302 Found`, redirects to login |
| Jobs schema | dynamic `compose_exec postgres psql -U scouter -d scouter -c '\dt job*'` | Pass: `jobs` and `job_logs` present on fresh volume |
| Diagnostic host HTTP exposure | `curl -sS -o /dev/null -w '%{http_code}' http://localhost:8080/diagnostic.php` | Pass: `404` |
| Diagnostic container HTTP exposure | dynamic `compose_exec scouter curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/diagnostic.php` | Pass: `404` |
| Diagnostic CLI behavior | dynamic `compose_exec scouter php /app/web/diagnostic.php` | Pass: DB connection, key tables, and directories checked |
| Demo user default behavior | dynamic `compose_exec scouter php /app/scripts/create-demo-user.php` | Pass: exits non-zero and refuses to create demo admin |
| Demo user absence | dynamic `compose_exec postgres psql -U scouter -d scouter -tAc "SELECT count(*) FROM users WHERE email = 'demo@scouter.local';"` | Pass: `0` |
| Pest suite | dynamic `compose_exec scouter ./vendor/bin/pest` | Pass: 392 passed, 2 skipped, 941 assertions |
| Final stack status | `docker compose -f docker-compose.local.yml ps` | Pass: postgres, clickhouse, renderers, scouter, workers, mcp, and crawler-go up |

## Reproducible Script

The validation can be replayed with:

```sh
tests/validation/run-local-dev-install-hardening.sh
```

For the destructive fresh-install validation that drops Compose volumes before rebuilding and starting the stack:

```sh
tests/validation/run-local-dev-install-hardening.sh --fresh --yes
```

The script resolves container IDs dynamically through `docker compose ps -q <service>` when supported, then falls back to Compose service labels for Podman Compose. It does not depend on dash-style or underscore-style container names. It also polls service readiness instead of relying on a fixed sleep.

## Notes

- The first demo-user check accidentally ran against an old `scouter` container that had not been recreated after the image build. It created `demo@scouter.local`; the row was immediately removed with:

  ```sh
  docker exec scouter_postgres_1 psql -U scouter -d scouter -c "DELETE FROM users WHERE email = 'demo@scouter.local';"
  ```

- After forcing container recreation and stabilizing the stack, the corrected script was verified in the container and refused by default.
- The replayable script was run with `--fresh --yes` after the manual checks. This performed `down -v --remove-orphans`, rebuilt the stack, and confirmed `jobs` / `job_logs` on a fresh Postgres volume.
- The successful fresh run log was written to `tests/validation/local-dev-install-hardening.20260623-140945.log`. Validation logs are ignored by Git via `tests/validation/*.log`.
