<div align="center">

# 🛰️ Scouter SEO Crawler

### The open-source SEO crawler built for the AI era

**AI-powered. MCP-ready. Self-hosted. Free forever.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Version](https://img.shields.io/badge/version-2.0.0-blue)]()
[![PHP](https://img.shields.io/badge/PHP-8.1+-purple)]()
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15+-blue)]()
[![Docker](https://img.shields.io/badge/Docker-required-blue)]()
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat-square)](#-contributing)

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

Scouter is a full-featured SEO crawler you run on your own server. Think Screaming Frog — but web-based, multi-user, **free**, fully open source, and now AI-native with a built-in MCP server so your favourite agents can crawl, query, and analyse sites on their own.

Built by an SEO consultant ([Lokoé](https://lokoe.fr)) for SEO consultants, devs, and anyone tired of paying $259/year for a desktop tool that hasn't changed since 2010.

---

## ✨ Why Scouter?

- 🤖 **AI-native** — embedded chatbot (Dr. Brief) that analyses your crawl conversationally. Auto-categorise pages with LLMs. No upsell, no API-key gymnastics.
- 🔌 **MCP server included** — plug Scouter into Claude, Cursor, or any MCP client and let your agents trigger crawls and query SEO data on their own. **The first open-source SEO crawler with native MCP support.**
- 🌐 **Modern web UI** — multi-user, multi-project, accessible from anywhere on your network. No clunky desktop app, no file-locking nightmares.
- 🆓 **Genuinely free** — no URL caps, no feature gates, no "pro tier coming soon".
- 🐳 **One-command Docker install** — running in under 2 minutes.

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
| **Connexion Cloud / OAuth (Claude.ai Web)** | ✅ | ❌ | ❌ |
| Price | **Free** | $279/yr | from $18/mo |

---

## 🔥 Key features

### 🤖 AI-powered

- Dr. Brief - embedded AI assistant that answers questions about your crawl in plain English: *"which pages have weak internal linking?"*, *"summarise my duplicate content issues"*, *"flag the URLs missing structured data"*.
- AI categorisation - auto-classify pages by intent, template, or any custom taxonomy.
- AI Bulk Generation – Bulk generate content based on your crawl
- Bring your own LLM — works with OpenAI, Anthropic, Gemini, or local stacks (Ollama, vLLM).

<img width="999" height="692" alt="image" src="https://github.com/user-attachments/assets/6c0367dd-5b37-4994-abd8-4c279b5afdd9" />

### 🔌 MCP server

- Native [Model Context Protocol](https://modelcontextprotocol.io) server.
- Trigger crawls, query results, and generate reports from Claude, Cursor, or any MCP client.
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

## 🚀 Quick start <span id="quick-start></div>

```bash
git clone https://github.com/lokoe-mehdi/scouter.git
cd scouter
chmod +x start.sh && ./start.sh
```

Then open [http://localhost:8080](http://localhost:8080) and create your admin account.

**Requirements:** Linux or WSL on Windows, with Docker installed.

### Deploy with Coolify

Scouter ships with a production-ready `docker-compose.yml`. One-click deploy on [Coolify](https://coolify.io/) or any Docker host.

---

## 🛠️ Tech stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.1+ |
| Database | PostgreSQL 15+ |
| JS rendering | Go + Chromedp |
| Containerisation | Docker + Docker Compose |
| Frontend | Vanilla HTML / CSS / JS (no build step) |
| Tests | Pest PHP |

<details>
<summary>📁 Repository layout</summary>

```
scouter/
├── app/
│   ├── Analysis/       # SEO analysis (Simhash, robots.txt, post-processing)
│   ├── Auth/           # Authentication & permissions
│   ├── Cli/            # CLI commands (crawl, resume, stop)
│   ├── Core/           # Crawler core (orchestrator, depth, page)
│   ├── Database/       # PostgreSQL repositories
│   ├── Http/           # REST API (router, controllers)
│   ├── Job/            # Async job manager
│   └── Util/           # XPath/Regex parser, JS renderer client
├── renderer/           # Go + Chromedp JS renderer
├── web/                # Web UI (pages, components, assets, API)
├── docker/             # Docker configuration
├── migrations/         # PostgreSQL migrations
├── tests/              # Pest tests (Unit + Feature)
├── docs/               # Documentation
└── cat.yml             # Default categorisation template
```

</details>

---

## 📚 Documentation

- [Installation](docs/INSTALLATION.md) — Docker, configuration, first launch
- [Usage guide](docs/UTILISATION.md) — the UI, step by step
- [Architecture](docs/ARCHITECTURE.md) — database, API, migrations
- [REST API reference](docs/ROUTER.md)
- [Worker architecture](docs/WORKER_ARCHITECTURE_PLAN.md)
- [Testing guide](docs/TESTING.md)
- [PHP class reference](docs/phpdoc/index.html) — generated with Doctum

### Useful commands

```bash
./start.sh                                          # Start (rebuild + up)
docker-compose down                                 # Stop
docker-compose logs -f app                          # Application logs
docker-compose logs -f worker                       # Worker logs
docker exec -it scouter bash                        # Container shell
docker exec scouter php /app/migrations/migrate.php # Run migrations
docker exec scouter ./vendor/bin/pest               # Run tests
./generate-docs.sh                                  # Regenerate PHP docs
```

---

## 🤝 Contributing

PRs welcome — open an issue first if you're planning a large change so we can align.

If you build something cool on top of Scouter (an integration, a custom analyser, an agent), please share it. The point of going open source is to compound on what each of us builds.

---

## 📄 License

MIT — see [LICENSE](LICENSE). Copyright © 2026 **Lokoé SASU**.

---

<div align="center">

**Built with ❤️ by [Lokoé](https://lokoe.fr) — an indie SEO consultant.**

⭐ Star the repo if Scouter saves you a Screaming Frog licence. It really helps.

</div>
