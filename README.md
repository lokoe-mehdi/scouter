<div align="center">
<img width="100" height="100" alt="image" src="https://github.com/user-attachments/assets/13212ec4-4548-4ddb-864f-ca84ab20ecec" /> 

# Scouter SEO Crawler
### The open-source SEO crawler built for the AI era

**AI-powered. MCP-ready. Self-hosted. Free forever.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Tests](https://github.com/lokoe-mehdi/scouter/actions/workflows/tests.yml/badge.svg)](https://github.com/lokoe-mehdi/scouter/actions/workflows/tests.yml)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat-square)](#-contributing)
&nbsp;
![Version](https://img.shields.io/badge/version-0.7.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.1+-purple)
![Go](https://img.shields.io/badge/Go-1.25+-00ADD8)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15+-blue)
![ClickHouse](https://img.shields.io/badge/ClickHouse-24+-FFCC01)
![Docker](https://img.shields.io/badge/Docker-required-blue)

[**Quick start**](#-quick-start) · [**Features**](#-key-features) · [**Why Scouter**](#-why-scouter) · [**Docs**](#-documentation)

</div>

---

<div align="center">

### 🎬 Watch the 2-minute demo

[![Watch the Scouter demo](https://github.com/user-attachments/assets/744c6356-2172-4537-9cbd-09a3e192ab26)](https://youtu.be/WjL4pgtBM6w)

*Click the thumbnail to see Scouter crawl, analyse and chat with a real site.*

</div>

---

## What is Scouter?

Scouter is a full-featured SEO crawler you run on your own server. Think Screaming Frog, but web-based, multi-user, **free**, fully open source, and now AI-native with a built-in MCP server so your favourite agents can crawl, query, and analyse sites on their own.

Built by an SEO consultant ([Lokoé](https://lokoe.fr)) for SEO consultants, devs, and anyone tired of paying $259/year for a desktop tool that hasn't changed since 2010.

---

## ✨ Why Scouter?

- 🤖 **AI-native:** embedded chatbot (Dr. Brief) that analyses your crawl conversationally. Auto-categorise pages with LLMs. No upsell, no API-key gymnastics.
- 🔌 **MCP server included:** plug Scouter into Claude, Cursor, or any MCP client and let your agents trigger crawls and query SEO data on their own. **The first open-source SEO crawler with native MCP support.**
- 🌐 **Modern web UI:** multi-user, multi-project, accessible from anywhere on your network. No clunky desktop app, no file-locking nightmares.
- 🆓 **Genuinely free:** no URL caps, no feature gates, no "pro tier coming soon".
- 🐳 **One-command Docker install:** running in under 2 minutes.

---

## 🆚 How Scouter compares

| | **Scouter** | Screaming Frog | Sitebulb |
|---|:---:|:---:|:---:|
| Open source | ✅ | ❌ | ❌ |
| Free, unlimited URLs | ✅ | ❌ | ❌ |
| Web-based UI | ✅ | ❌ | ✅ |
| Self-hosted | ✅ | ❌ | ❌ |
| Multi-user | ✅ | ❌ | ✅ |
| JavaScript rendering | ✅ | ✅ | ✅ |
| Internal PageRank | ✅ | ✅ | ✅ |
| Custom extractors (XPath / Regex) | ✅ | ✅ | ✅ |
| SQL queries on crawl data | ✅ | ❌ | ❌ |
| **AI assistant (chat with your crawl)** | ✅ | ❌ | ❌ |
| **AI page categorisation** | ✅ | ❌ | ❌ |
| **MCP server** | ✅ | ✅ | ❌ |
| **Cloud connection / OAuth (Claude.ai Web)** | ✅ | ❌ | ❌ |
| Price | **Free** | $279/yr | from $18/mo |

---

## 🔥 Key features

### 🤖 AI-powered

- Dr. Brief: embedded AI assistant that answers questions about your crawl in plain English: *"which pages have weak internal linking?"*, *"summarise my duplicate content issues"*, *"flag the URLs missing structured data"*.
- AI categorisation: auto-classify pages by intent, template, or any custom taxonomy.
- AI bulk generation: bulk generate content based on your crawl.
- Bring your own LLM: works with OpenAI, Anthropic, Gemini, or local stacks (Ollama, vLLM).

<img width="999" height="692" alt="image" src="https://github.com/user-attachments/assets/6c0367dd-5b37-4994-abd8-4c279b5afdd9" />

### 🔌 MCP server

- Native [Model Context Protocol](https://modelcontextprotocol.io) server.
- Trigger crawls, query results, (re)categorise pages, and generate reports from Claude, Cursor, or any MCP client.
- Build real SEO agents that actually have crawl data to work with.

### 🕷️ Crawl engine

- **JavaScript Rendering:** Full support for dynamic content rendering.
- **Rate Limiting:** Adjust speed by setting a maximum number of URLs crawled per second.
- **Advanced Configuration:** Fine-tune crawls with maximum depth levels, robots.txt/nofollow compliance, canonical tags management, redirect following, HTML storage, failed URL retries, HTTP authentication, and custom headers.
- **Distributed Async Workers:** Scalable, Docker-based worker architecture.
- **Crawl Control:** Pause, resume, stop, and restart any crawl job at any time.

### 📊 SEO analysis

- On-page: title, H1, meta description, heading structure, word count.
- Technical: status codes, response times, redirect chains.
- Duplicate content detection (Simhash).
- Structured data (JSON-LD) detection.
- Internal linking analysis with **PageRank computation**.
- Sitemap analysis: XML parsing, coverage gaps, orphan pages.

### 🎯 Custom extraction

- XPath extractors on any HTML element.
- Regex extractors on raw source.
- YAML *or* visual drag-and-drop categorisation rules.

### 🎨 Interface

- Multi-project dashboard with charts and KPIs.
- Filterable URL explorer (filter by any column, any condition).
- Built-in **SQL explorer** for custom queries on crawl data.
- CSV export.
- Multi-user roles: admin / user / viewer.

---

## 🚀 Quick start

```bash
git clone https://github.com/lokoe-mehdi/scouter.git
cd scouter
chmod +x start.sh && ./start.sh
```

Then open [http://localhost:8080](http://localhost:8080) and create your admin account.

**Requirements:** Linux or WSL on Windows, with Docker installed.

### Deploy with Coolify

Scouter ships with a production-ready `docker-compose.yml`. One-click deploy on [Coolify](https://coolify.io/) or any Docker host.

### Upgrading

Pull and re-run `./start.sh`:

```bash
git pull && ./start.sh
```

`start.sh` rebuilds the changed images and starts the new services (the crawler
is now a Go service, `crawler-go`). **Your data is preserved:** `start.sh` never
touches the `postgres_data` volume.

> ⚠️ Do **not** use `./bin/rebuild.sh` to upgrade: it wipes all volumes (`-v`),
> including the database. It's only for a from-scratch local reset.
>
> ℹ️ On the first start after upgrading, `crawler-go` compiles once (~1-2 min,
> needs internet). Crawls stay `queued` until it's up, so check
> `docker compose -f docker-compose.local.yml logs -f crawler-go`
> (it should print *"Go crawler started"*). On production (`docker-compose.yml`),
> just redeploy/rebuild so the Go binary is built.

---

## 🛠️ Tech stack

| Layer | Tech |
|---|---|
| Crawl engine + post-processing | **Go 1.25** (`crawler-go/`) |
| JS rendering | **Go + Rod** (headless Chrome, `renderer/`) |
| Back office (UI / REST API / async jobs) | PHP 8.1+ |
| Database | PostgreSQL 15+ (metadata + crawl frontier) · ClickHouse 24+ (crawl data warehouse + post-processing) |
| Containerisation | Docker + Docker Compose |
| Frontend | Vanilla HTML / CSS / JS (no build step) |
| Tests | Pest (PHP) + `go test` (Go) |

<details>
<summary>📁 Repository layout</summary>

```
scouter/
├── app/                # PHP back office (UI / API / jobs, NOT the crawl)
│   ├── Analysis/       # Categorisation (CategorizationService)
│   ├── Auth/           # Authentication & permissions
│   ├── Cli/            # CLI commands (batch-categorize, delete...)
│   ├── Database/       # PostgreSQL repositories
│   ├── Http/           # REST API (router, controllers)
│   ├── Job/            # Async job manager
│   └── Util/           # Helpers (SafeHttp anti-SSRF...)
├── crawler-go/         # Crawl engine + post-processing (Go), see refacto.md
├── renderer/           # Go + Rod (headless Chrome) JS renderer
├── web/                # Web UI (pages, components, assets, API)
├── bin/                # Shell utilities (rebuild, check-health, clean-jobs)
├── docker/             # Docker configuration
├── migrations/         # PostgreSQL migrations
├── tests/              # Pest tests (Unit + Feature) + tests/parity
└── cat.yml             # Default categorisation template
```

</details>

---

## 📚 Documentation

- [REST API](web/openapi.yaml): OpenAPI specification

### Useful commands

```bash
./start.sh                                          # Start (rebuild + up)
docker compose -f docker-compose.local.yml down     # Stop
docker compose -f docker-compose.local.yml logs -f crawler-go   # Crawler (Go) logs
docker compose -f docker-compose.local.yml logs -f worker       # PHP worker logs
docker exec scouter php /app/migrations/migrate.php # Run migrations
docker exec scouter ./vendor/bin/pest               # Run PHP tests (Pest)
docker compose -f docker-compose.local.yml exec crawler-go go test ./...  # Run Go tests
bash tests/parity/run_categorization_parity.sh      # Go↔PHP categorization parity
./bin/rebuild.sh                                    # Full clean rebuild
./bin/check-health.sh                               # Health check
```

---

## 🤝 Contributing

PRs welcome: open an issue first if you're planning a large change so we can align. See [CONTRIBUTING.md](CONTRIBUTING.md) for the dev setup, where code goes (PHP back office vs Go crawler), how to run the tests, and the PR process.

If you build something cool on top of Scouter (an integration, a custom analyser, an agent), please share it. The point of going open source is to compound on what each of us builds.

---

## 📄 License

MIT. See [LICENSE](LICENSE). Copyright © 2026 **Lokoé SASU**.

---

<div align="center">

**Built with ❤️ by [Lokoé](https://lokoe.fr), an indie SEO consultant.**

⭐ Star the repo if Scouter saves you a licence. It really helps.

</div>
