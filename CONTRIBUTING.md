# Contributing to Scouter

Thanks for taking the time to contribute. Scouter is built in the open so that
everyone can compound on what each of us builds. Bug reports, fixes, features,
docs, and translations are all welcome.

## Table of contents

- [Ways to contribute](#ways-to-contribute)
- [Reporting bugs](#reporting-bugs)
- [Suggesting features](#suggesting-features)
- [Development setup](#development-setup)
- [Project architecture (where code goes)](#project-architecture-where-code-goes)
- [Running the tests](#running-the-tests)
- [Coding conventions](#coding-conventions)
- [Submitting a pull request](#submitting-a-pull-request)
- [License](#license)

## Ways to contribute

- Report a bug or a regression.
- Fix an open issue (look for `good first issue` if you are new).
- Propose or build a feature.
- Improve the docs, the README, or the API spec (`web/openapi.yaml`).
- Add a UI translation (see `web/lang/`).

Not everything has to be code: triaging issues, reproducing bugs, and improving
wording all help.

## Reporting bugs

Open a [GitHub issue](../../issues) and include:

1. What you did (the steps to reproduce, ideally a minimal case).
2. What you expected to happen.
3. What actually happened (error messages, screenshots, relevant logs).
4. Your environment: OS, Docker version, and whether you run the local or
   production compose file.

Useful logs:

```bash
docker compose -f docker-compose.local.yml logs --tail=100 crawler-go   # crawl engine (Go)
docker compose -f docker-compose.local.yml logs --tail=100 worker       # PHP jobs
docker compose -f docker-compose.local.yml logs --tail=100 scouter      # web / API
```

## Suggesting features

For anything bigger than a small fix, please **open an issue first** so we can
agree on the approach before you spend time on it. Describe the problem you are
solving, not only the solution: it helps us find the best fit for the project.

## Development setup

Requirements: Linux or WSL on Windows, with Docker installed.

```bash
git clone https://github.com/lokoe-mehdi/scouter.git
cd scouter
chmod +x start.sh && ./start.sh
```

Then open http://localhost:8080 and create your admin account.

`start.sh` runs the local compose file (`docker-compose.local.yml`), which mounts
`./app` and `./web` as volumes: editing PHP code is reflected without a rebuild
(restart the affected container to pick it up).

The Go crawler runs from source in its container via `go run`, so after editing
anything in `crawler-go/` you just recompile by restarting it:

```bash
docker compose -f docker-compose.local.yml restart crawler-go
```

## Project architecture (where code goes)

Scouter is split in two languages on purpose. Put your change in the right place:

- **`crawler-go/`** (Go): the crawl engine and post-processing (fetch, parse,
  links, inlinks, PageRank, duplicates, redirect chains, sitemap). This is where
  crawling behaviour lives.
- **`renderer/`** (Go + Rod): the headless-Chrome JavaScript renderer.
- **`app/`** (PHP): the back office only: web UI controllers, REST API, auth,
  async jobs (delete, batch-categorize, bulk-ai), and the scheduler. It does
  **not** run crawls anymore.
- **`web/`**: the front-end (pages, components, assets) and the OpenAPI spec.

The Go crawler architecture and the rationale for the split are documented in
`refacto.md`. The PostgreSQL schema is shared by both languages and lives in
`docker/postgres/init.sql` + `migrations/`.

> One important rule: page categorisation exists in **both** Go (in the crawl)
> and PHP (for the UI / API / AI / batch jobs). This duplication is intentional
> and guarded by a parity test. If you change categorisation logic, update
> **both** sides and keep `tests/parity` green.

## Running the tests

Two test suites, both run in CI on every pull request.

```bash
# PHP (Pest)
docker exec scouter ./vendor/bin/pest

# Go crawler (unit + parity)
docker compose -f docker-compose.local.yml exec crawler-go go test ./...

# Go <-> PHP categorization parity (spins a throwaway Postgres)
bash tests/parity/run_categorization_parity.sh
```

Please add or update tests for any behaviour you change. New crawl logic in Go
should come with a table-driven test; bug fixes should come with a test that
fails before the fix.

## Coding conventions

- **Go**: code must be `gofmt`-clean and pass `go vet ./...` (CI enforces both).
  Keep packages small and focused; match the style of the surrounding code.
- **PHP**: follow the style already in the file you are editing (PSR-4 autoload,
  4-space indent). Do not introduce a new framework or dependency without
  discussing it in an issue first.
- Keep comments useful: explain the "why", not the obvious "what".
- Match the existing naming and structure rather than reformatting unrelated code.

## Submitting a pull request

1. Fork the repo and create a branch from `main` (for example
   `fix/redirect-loop` or `feat/sitemap-priority`).
2. Make your change in focused commits with clear, imperative messages
   (for example `fix: stop re-queuing running crawls on restart`).
3. Make sure both test suites pass locally and add tests for your change.
4. Open a pull request against `main`, link the related issue, and describe what
   you changed and why. Screenshots help for UI changes.
5. CI must be green before review. Keep PRs small: one logical change per PR is
   much easier to review and merge.

Be respectful and constructive in issues and reviews. We want this to be a
friendly place to build.

## License

By contributing, you agree that your contributions are licensed under the
[MIT License](LICENSE), the same license as the project.
