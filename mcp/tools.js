/**
 * Tool catalog + dispatch logic for the Scouter MCP server, kept free of any
 * transport/SDK imports so it can be unit-tested in isolation (node:test) with a
 * mocked global fetch. `server.js` wires these into the MCP runtime.
 */

export const API_BASE =
  (process.env.SCOUTER_API_URL || 'http://scouter:8080').replace(/\/+$/, '') + '/api/v1';

export const TOOLS = [
  {
    name: 'list_projects',
    description: 'List the Scouter projects the API key can access (admin sees all; other roles see owned + shared). Paginated.',
    inputSchema: {
      type: 'object',
      properties: {
        limit: { type: 'integer', default: 50, maximum: 200, description: 'Max rows (<= 200).' },
        offset: { type: 'integer', default: 0, minimum: 0 },
      },
    },
  },
  {
    name: 'list_crawls',
    description: "List a project's crawls, newest first. Paginated.",
    inputSchema: {
      type: 'object',
      properties: {
        project_id: { type: 'integer', description: 'Project id (see list_projects).' },
        limit: { type: 'integer', default: 50, maximum: 200 },
        offset: { type: 'integer', default: 0, minimum: 0 },
      },
      required: ['project_id'],
    },
  },
  {
    name: 'get_crawl',
    description: "Get a crawl's metadata (domain, status, URL count, start/finish dates) AND its full configuration under a `config` key (start URLs, limits, filters, rendering, extractors…).",
    inputSchema: {
      type: 'object',
      properties: { crawl_id: { type: 'integer' } },
      required: ['crawl_id'],
    },
  },
  {
    name: 'get_crawl_schema',
    description:
      'Get the queryable tables and columns for a crawl. ALWAYS the first call when auditing a crawl. ' +
      "Right after it, run a categories query (SELECT c.cat, COUNT(*) FROM pages p JOIN crawl_categories c ON p.cat_id=c.id WHERE p.crawled AND p.in_crawl GROUP BY c.cat ORDER BY 2 DESC) — categories are the unit of analysis and every audit must start there.",
    inputSchema: {
      type: 'object',
      properties: { crawl_id: { type: 'integer' } },
      required: ['crawl_id'],
    },
  },
  {
    name: 'get_page_content',
    description:
      "Get the readable content of ONE page from its stored HTML: title, ordered h1..h6 headings, and the visible text (scripts/styles stripped). " +
      'Use this when the user asks what a page says / its headings / its content — instead of guessing from SQL columns (only h1 is in SQL; h2-h6 and body text are not). ' +
      'Returns has_html=false if the crawl did not keep raw HTML for that URL.',
    inputSchema: {
      type: 'object',
      properties: {
        crawl_id: { type: 'integer' },
        url: { type: 'string', description: 'Full page URL, matching pages.url exactly (get it from run_sql first).' },
      },
      required: ['crawl_id', 'url'],
    },
  },
  {
    name: 'get_page_html',
    description:
      'Get the RAW HTML markup of ONE page (decoded from the crawl storage). Use it for structural inspection SQL can\'t answer: pagination component, nav, breadcrumbs, JSON-LD, anchor patterns. ' +
      'HTML can be large — it is capped by max_chars (default 50000 here to protect context); raise it only if you truly need more. For readable text/headings prefer get_page_content instead.',
    inputSchema: {
      type: 'object',
      properties: {
        crawl_id: { type: 'integer' },
        url: { type: 'string', description: 'Full page URL, matching pages.url exactly.' },
        max_chars: { type: 'integer', default: 50000, description: 'Cap on returned HTML length.' },
      },
      required: ['crawl_id', 'url'],
    },
  },
  {
    name: 'run_sql',
    description:
      "Run ONE read-only PostgreSQL SELECT/WITH over a crawl (whitelisted tables: pages, links, crawl_categories, duplicate_clusters, page_schemas, redirect_chains), paginated (page, page_size, count). " +
      'METHODOLOGY (follow it): (1) For an audit, FIRST query the categories, then run ONE consolidated "site overview" query with COUNT(*) FILTER(WHERE …) GROUP BY category to get many KPIs at once — do NOT fire 50 tiny queries. ' +
      '(2) ALWAYS split distributions/KPIs BY CATEGORY (GROUP BY the category), not only globally — category is the level that localizes problems to a template. ' +
      '(3) Scope real pages with "crawled = true AND in_crawl = true". (4) Never end the SQL with ";" (the server appends LIMIT/OFFSET). Use the get_crawl_schema output for exact column names.',
    inputSchema: {
      type: 'object',
      properties: {
        crawl_id: { type: 'integer' },
        query: { type: 'string', description: 'e.g. SELECT url, code FROM pages WHERE code >= 400 ORDER BY inlinks DESC' },
        page: { type: 'integer', default: 1, minimum: 1 },
        page_size: { type: 'integer', default: 100, maximum: 1000 },
        count: { type: 'boolean', default: true, description: 'Set false to skip the COUNT(*) total (faster streaming).' },
      },
      required: ['crawl_id', 'query'],
    },
  },
  {
    name: 'create_crawl',
    description:
      'Create AND queue a new crawl from a config object {general:{…}, advanced:{…}}. The ONLY mandatory field is the start URL — every other key keeps a sensible template default unless the user explicitly asks to set it. Returns crawl_id + status "queued". Requires a non-viewer key.\n' +
      'KEYS — general: ' +
      'start (start URL, required) · ' +
      'crawl_type = WHICH urls: "spider" (follow links, discover the whole site) | "list" (crawl only url_list, no link-following) · ' +
      'crawl_mode = HOW pages load: "classic" (HTTP, fast) | "javascript" (headless render, sees JS content, slower) · ' +
      'crawl_speed = throughput/politeness: "very_slow" (~1 URL/s) | "slow" (~5 URL/s) | "fast" (~20 URL/s) | "unlimited" (no limit) · ' +
      'depthMax (1–100, spider) · domains (allowed domains; defaults to the start URL domain) · url_list (list mode only) · "user-agent".\n' +
      'advanced: respect_robots, respect_nofollow, respect_canonical, follow_redirects, retry_failed_urls, store_html (bool) · sitemap_urls (array) · custom_headers (array) · http_auth (null or {username,password}) · xPathExtractors {name:xpath} · regexExtractors {name:regex}.\n' +
      'EXAMPLE spider: {"general":{"start":"https://www.website.tld/","crawl_type":"spider","crawl_mode":"classic","crawl_speed":"fast","depthMax":30},"advanced":{"store_html":true,"xPathExtractors":{"count_h2":"count(//h2)"}}}. ' +
      'EXAMPLE list: {"general":{"crawl_type":"list","url_list":["https://www.website.tld/page-1","https://www.website.tld/page-2"]}}.',
    inputSchema: {
      type: 'object',
      properties: {
        config: {
          type: 'object',
          additionalProperties: true,
          description: 'Crawl config {general,advanced}. Minimum: {general:{start:"https://…"}}.',
        },
      },
      required: ['config'],
    },
  },
];

/** Low-level call to the Scouter REST API. `token` is forwarded verbatim. */
export async function callApi(token, method, path, { query, body } = {}) {
  let url = API_BASE + path;
  if (query) {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) if (v !== undefined && v !== null) qs.set(k, String(v));
    const s = qs.toString();
    if (s) url += '?' + s;
  }
  const headers = { Accept: 'application/json' };
  if (token) headers.Authorization = token;
  const opts = { method, headers };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  const r = await fetch(url, opts);
  const text = await r.text();
  let data;
  try { data = JSON.parse(text); } catch { data = text; }
  return { ok: r.ok, status: r.status, data };
}

/**
 * Map an MCP tool name + arguments to the matching API call.
 * Returns the callApi result, or null for an unknown tool.
 */
export async function dispatch(token, name, args = {}) {
  switch (name) {
    case 'list_projects':
      return callApi(token, 'GET', '/projects', { query: { limit: args.limit, offset: args.offset } });
    case 'list_crawls':
      return callApi(token, 'GET', `/projects/${encodeURIComponent(args.project_id)}/crawls`, { query: { limit: args.limit, offset: args.offset } });
    case 'get_crawl':
      return callApi(token, 'GET', `/crawls/${encodeURIComponent(args.crawl_id)}`);
    case 'get_crawl_schema':
      return callApi(token, 'GET', `/crawls/${encodeURIComponent(args.crawl_id)}/schema`);
    case 'get_page_content':
      return callApi(token, 'GET', `/crawls/${encodeURIComponent(args.crawl_id)}/content`, { query: { url: args.url } });
    case 'get_page_html':
      return callApi(token, 'GET', `/crawls/${encodeURIComponent(args.crawl_id)}/html`, { query: { url: args.url, max_chars: args.max_chars ?? 50000 } });
    case 'run_sql':
      return callApi(token, 'POST', `/crawls/${encodeURIComponent(args.crawl_id)}/query`, {
        body: { query: args.query, page: args.page, page_size: args.page_size, count: args.count },
      });
    case 'create_crawl':
      return callApi(token, 'POST', '/crawls', { body: { config: args.config } });
    default:
      return null;
  }
}
