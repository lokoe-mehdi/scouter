# Scouter JS Renderer (Go/Rod)

High-performance HTTP service to render JavaScript-heavy pages using **Go + Rod** (Chrome DevTools Protocol).

## Performance

- **2-3x faster** than Puppeteer/Node.js
- ~8 URLs/sec per container
- 50 concurrent pages max per container
- Ultra-low memory footprint (~50MB vs ~200MB for Puppeteer)

## API

### POST /render

Single URL rendering.

```json
{"url": "https://example.com", "headers": {"User-Agent": "Custom"}}
```

### POST /render-batch

Batch rendering (up to 20 URLs in parallel).

```json
{"urls": ["https://example.com", "https://google.com"], "headers": {}}
```

### GET /health

Health check → `{"status": "ok", "engine": "rod"}`