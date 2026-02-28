# Scouter

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat-square)
![Made with Love](https://img.shields.io/badge/Made%20with-❤️-red)

**Professional SEO Crawler** with web-based analysis interface, built by [Lokoé](https://lokoe.fr).

![Version](https://img.shields.io/badge/version-2.0.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.1+-purple)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15+-blue)
![Docker](https://img.shields.io/badge/Docker-required-blue)

---

## Quick Install

```bash
git clone https://github.com/lokoe-mehdi/scouter.git && cd scouter && chmod +x start.sh && ./start.sh
```

**Requirements:** Linux or WSL on Windows, with Docker installed.

**Access:** http://localhost:8080
On first launch, you'll be prompted to create an admin account.

### Deployment

Scouter can also be easily deployed with [Coolify](https://coolify.io/) using the provided `docker-compose.yml`.

---

## Features

### Crawl
- **Multi-depth**: Configurable crawl depth (0 to N)
- **Robots.txt**: Respects Allow/Disallow directives
- **Canonical**: Detection and tracking of canonical tags
- **JavaScript**: Rendering mode via Puppeteer (SPA support)
- **Parallelism**: Configurable concurrent requests
- **Docker Workers**: Distributed architecture with async workers

### SEO Analysis
- **On-page**: Title, H1, meta description, headings
- **Technical**: HTTP status codes, response times, redirects
- **Content**: Word count, duplicate detection (Simhash)
- **Structured Data**: JSON-LD schema detection
- **Internal Linking**: Inlinks, outlinks, internal PageRank

### Custom Extractors
- **XPath**: Extract any HTML element
- **Regex**: Pattern matching on source code

### Categorization
- **YAML Editor**: Configure categorization rules
- **Visual Mode**: Drag & drop interface for rules
- **Test Mode**: Preview before applying
- **Default Template**: `cat.yml` applied automatically

### Interface
- **Dashboard**: Overview with charts
- **Explorer**: Filterable table of all URLs
- **SQL Explorer**: Custom SQL queries
- **CSV Export**: Data download
- **Multi-user Management**: Admin/user/viewer roles

---

## Architecture

```
scouter/
├── app/
│   ├── Analysis/           # SEO analysis
│   │   ├── PostProcessor.php   # Crawl post-processing
│   │   ├── RobotsTxt.php       # Robots.txt parser
│   │   └── Simhash.php         # Duplicate detection
│   ├── Auth/               # Authentication
│   │   └── Auth.php            # Session & permission management
│   ├── Cli/                # CLI tools
│   │   └── Cmder.php           # Crawl/resume/stop commands
│   ├── Core/               # Crawler core
│   │   ├── Crawler.php         # Main orchestrator
│   │   ├── DepthCrawler.php    # Depth-based crawling
│   │   ├── Page.php            # Page analysis
│   │   └── PageCrawler.php     # Single page crawl
│   ├── Database/           # Data layer (PostgreSQL)
│   │   ├── PostgresDatabase.php    # Singleton connection
│   │   ├── CrawlDatabase.php       # Crawl queries
│   │   ├── CrawlRepository.php     # Crawl CRUD
│   │   ├── ProjectRepository.php   # Project CRUD
│   │   ├── UserRepository.php      # User CRUD
│   │   ├── CategoryRepository.php  # Category CRUD
│   │   ├── PageRepository.php      # Page CRUD
│   │   └── LinkRepository.php      # Link CRUD
│   ├── Http/               # HTTP layer (REST API)
│   │   ├── Router.php          # REST router
│   │   ├── Request.php         # HTTP request wrapper
│   │   ├── Response.php        # JSON responses
│   │   ├── Controller.php      # Base controller class
│   │   └── Controllers/        # API controllers
│   │       ├── ProjectController.php
│   │       ├── CrawlController.php
│   │       ├── UserController.php
│   │       ├── CategoryController.php
│   │       ├── CategorizationController.php
│   │       ├── JobController.php
│   │       ├── QueryController.php
│   │       ├── ExportController.php
│   │       └── MonitorController.php
│   ├── Job/                # Async job management
│   │   └── JobManager.php      # Queue and job status
│   ├── Util/               # Utilities
│   │   ├── HtmlParser.php      # XPath/Regex parsing
│   │   ├── JsRenderer.php      # JavaScript rendering client
│   │   └── UrlHelper.php       # URL manipulation
│   └── bin/                # Executable scripts
│       └── worker.php          # Docker worker
├── web/                    # Web interface
│   ├── api/
│   │   └── index.php       # Single REST API entry point
│   ├── pages/              # HTML pages
│   ├── components/         # Reusable components
│   └── assets/             # CSS/JS
├── docker/                 # Docker configuration
├── migrations/             # PostgreSQL migrations
├── tests/                  # Tests (Pest)
│   ├── Unit/               # Unit tests
│   └── Feature/            # Feature tests
├── docs/                   # Documentation
│   ├── phpdoc/             # PHP documentation (Doctum)
│   ├── ARCHITECTURE.md     # Technical architecture
│   ├── ROUTER.md           # Router documentation
│   ├── TESTING.md          # Testing guide
│   └── ...
└── cat.yml                 # Default categorization template
```

### Tech Stack

| Component | Technology |
|-----------|------------|
| Backend | PHP 8.1+ |
| Database | PostgreSQL 15+ |
| Frontend | HTML/CSS/JS vanilla |
| Containerization | Docker + Docker Compose |
| Tests | Pest PHP |
| Documentation | Doctum |
| JS Rendering | Go + Chromedp |

---

## Documentation

### User Guides
- [Installation](docs/INSTALLATION.md) - Docker, configuration, first launch
- [Usage](docs/UTILISATION.md) - User interface guide
- [Architecture](docs/ARCHITECTURE.md) - Database, API, migrations

### Technical Documentation
- [PHP Documentation](docs/phpdoc/index.html) - Class documentation (Doctum)
- [REST Router](docs/ROUTER.md) - Architecture and API endpoints
- [Tests](docs/TESTING.md) - Unit testing guide with Pest
- [Workers](docs/WORKER_ARCHITECTURE_PLAN.md) - Docker worker architecture

### Generate PHP Documentation

```bash
./generate-docs.sh
# Output in docs/phpdoc/
```

---

## Useful Commands

### Docker
```bash
./start.sh                              # Start (rebuild + up)
docker-compose down                     # Stop
docker-compose logs -f app              # Application logs
docker-compose logs -f worker           # Worker logs
docker exec -it scouter bash            # Container shell
```

### Database
```bash
docker exec scouter php /app/migrations/migrate.php  # Run migrations
```

### Tests
```bash
./vendor/bin/pest                       # Run tests (local)
docker exec scouter ./vendor/bin/pest   # Run tests (Docker)
```

### Documentation
```bash
./generate-docs.sh                      # Generate API docs
```

---

## REST API

The API uses a centralized router (`web/api/index.php`) with the following endpoints:

### Projects
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/projects` | List projects |
| POST | `/api/projects` | Create a project/crawl |
| DELETE | `/api/projects/{id}` | Delete a project |
| POST | `/api/projects/duplicate` | Duplicate a crawl |

### Crawls
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/crawls/info` | Crawl info |
| GET | `/api/crawls/running` | Running crawls |
| POST | `/api/crawls/start` | Start a crawl |
| POST | `/api/crawls/stop` | Stop a crawl |
| POST | `/api/crawls/resume` | Resume a crawl |

### Categorization
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/categorization/save` | Save and apply |
| POST | `/api/categorization/test` | Test without applying |
| GET | `/api/categorization/stats` | Statistics |

### Users (admin)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/users` | List users |
| POST | `/api/users` | Create a user |
| PUT | `/api/users/{id}` | Update a user |
| DELETE | `/api/users/{id}` | Delete a user |

---

## Main Classes

| Namespace | Class | Description |
|-----------|-------|-------------|
| `App\Core` | `Crawler` | Main crawl orchestrator |
| `App\Core` | `DepthCrawler` | Depth-based crawling with parallel requests |
| `App\Core` | `Page` | Page data analysis and extraction |
| `App\Database` | `PostgresDatabase` | PostgreSQL singleton connection |
| `App\Database` | `CrawlRepository` | Crawl CRUD operations |
| `App\Database` | `ProjectRepository` | Project CRUD operations |
| `App\Auth` | `Auth` | Authentication and access control |
| `App\Analysis` | `RobotsTxt` | Robots.txt parsing and interpretation |
| `App\Analysis` | `Simhash` | Duplicate content detection |
| `App\Util` | `JsRenderer` | JavaScript rendering client |
| `App\Http` | `Router` | REST API router |
| `App\Job` | `JobManager` | Async job management |

---

## License

This project is licensed under the **MIT License** - see the [LICENSE](LICENSE) file for details.

Copyright (c) 2026 **Lokoé SASU**


**Scouter** - Professional SEO Crawler by [Lokoé](https://lokoe.fr)
