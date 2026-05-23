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
    name: 'get_crawl_status',
    description: "Live status of a crawl: status (queued/running/finished/stopped), discovered vs crawled URL counts, the worker job status, and the latest log lines. Use it to follow a crawl after create_crawl. (No % progress — URL counts grow as discovery continues.)",
    inputSchema: {
      type: 'object',
      properties: { crawl_id: { type: 'integer' } },
      required: ['crawl_id'],
    },
  },
  {
    name: 'stop_crawl',
    description: 'Stop (or cancel) a running/queued crawl. Queued crawls are cancelled immediately; a running one gets a graceful stop (finishes the current batch). Requires management rights on the crawl’s project.',
    inputSchema: {
      type: 'object',
      properties: { crawl_id: { type: 'integer' } },
      required: ['crawl_id'],
    },
  },
  {
    name: 'start_crawl',
    description: 'Resume a fully STOPPED crawl (continues where it left off). Only works when the crawl status is "stopped"/"failed" — not while it is running, queued, or still "stopping". Requires management rights on the crawl’s project.',
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
    name: 'list_schedules',
    description: 'List all crawl schedules across accessible projects, INCLUDING disabled ones. Each: project_id, domain, enabled, frequency + timing, crawl_type, depth_max, next_run_at, last_triggered_at.',
    inputSchema: { type: 'object', properties: {} },
  },
  {
    name: 'get_schedule',
    description: "Get a project's crawl schedule (or null if none). Includes disabled schedules.",
    inputSchema: { type: 'object', properties: { project_id: { type: 'integer' } }, required: ['project_id'] },
  },
  {
    name: 'set_schedule',
    description:
      'Create or REPLACE a project\'s recurring-crawl schedule (one per project; re-calling fully replaces the previous one). ' +
      'template_crawl_id = an existing crawl OF THIS PROJECT whose config (mode, speed, extractors…) is reused for each scheduled run; required to create, optional when only changing timing. ' +
      'frequency: "daily" (at hour:minute) | "weekly" (on days_of_week at hour:minute) | "monthly" (on day_of_month at hour:minute). ' +
      'days_of_week = array of "mon","tue","wed","thu","fri","sat","sun" (weekly — multiple allowed). hour 0-23, minute 0-59 (server time). day_of_month = a SINGLE day 1-28 (monthly — only one day per month, no lists). enabled defaults true.',
    inputSchema: {
      type: 'object',
      properties: {
        project_id: { type: 'integer' },
        template_crawl_id: { type: 'integer', description: 'Existing crawl id of this project used as the config template.' },
        frequency: { type: 'string', enum: ['daily', 'weekly', 'monthly'] },
        hour: { type: 'integer', minimum: 0, maximum: 23, default: 8 },
        minute: { type: 'integer', minimum: 0, maximum: 59, default: 0 },
        days_of_week: { type: 'array', items: { type: 'string' }, description: 'mon..sun, weekly only (multiple allowed).' },
        day_of_month: { type: 'integer', minimum: 1, maximum: 28, description: 'monthly only — a single day (1-28).' },
        enabled: { type: 'boolean', default: true },
      },
      required: ['project_id', 'frequency'],
    },
  },
  {
    name: 'toggle_schedule',
    description: "Enable or disable a project's EXISTING schedule (without changing its settings). Disabling clears the next run; enabling recomputes it. Fails if no schedule exists yet (use set_schedule first).",
    inputSchema: {
      type: 'object',
      properties: { project_id: { type: 'integer' }, enabled: { type: 'boolean' } },
      required: ['project_id', 'enabled'],
    },
  },
  {
    name: 'delete_schedule',
    description: "Remove a project's crawl schedule entirely.",
    inputSchema: { type: 'object', properties: { project_id: { type: 'integer' } }, required: ['project_id'] },
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
  {
    name: 'get_categorization',
    description:
      "Get a crawl's categorization: the YAML rules (`config`), the categories actually APPLIED to this crawl (name, color, page count — name=null is the uncategorized bucket), and a `deployment` block. " +
      'Categories are the unit of analysis, so this is how you check what taxonomy a crawl uses. ' +
      'deployment.status tells you whether a project-wide re-categorization is still propagating to the OTHER crawls of the project: "running" (poll again), "completed", "failed", or "idle" (nothing pending). ' +
      'The crawl you target is always categorized immediately by set_categorization, so its `categories` are up to date the moment set_categorization returns — deployment only concerns the project\'s other crawls.',
    inputSchema: {
      type: 'object',
      properties: { crawl_id: { type: 'integer' } },
      required: ['crawl_id'],
    },
  },
  {
    name: 'set_categorization',
    description:
      'Set (replace) the categorization rules for a crawl, apply them SYNCHRONOUSLY to this crawl, and (by default) deploy them across every other crawl of the SAME project in the background. Requires management rights on the project.\n' +
      'WHAT CATEGORIES ARE: page TEMPLATES (homepage / product / category listing / blog_post / legal…), i.e. "which HTML template renders this URL", NOT topical themes. Aim for 4–7 categories. They are the unit of SEO analysis.\n' +
      'YAML FORMAT — a mapping of category_name → rule. Each rule has:\n' +
      '  · include : list of regex matched against the URL PATH (everything after the host, starting with "/"). A URL joins the category if AT LEAST ONE include matches.\n' +
      '  · exclude : (optional) list of regex; a URL is rejected if any exclude matches, even when include matched.\n' +
      '  · color   : hex color for the dashboard chart (e.g. "#6bd899").\n' +
      '  · dom     : (optional) the domain the rule applies to. OMIT it and Scouter fills in the crawl\'s domain automatically. Only set it to scope a rule to a specific domain.\n' +
      'RULES: patterns are PostgreSQL POSIX regex, case-insensitive (~*); anchor with ^ for "starts with" and $ for end-of-path. ORDER MATTERS — first matching category wins, so list narrow patterns (e.g. homepage ^/?$) before broad ones (e.g. a ".*" catch-all). Names: lowercase snake_case.\n' +
      'EXAMPLE yaml:\n' +
      'homepage:\n  include:\n    - ^/?$\n  color: "#4ecdc4"\nproduct:\n  include:\n    - ^/p/[0-9]+\n    - ^/product/[^/]+\n  exclude:\n    - /preview\n  color: "#6bd899"\nother:\n  include:\n    - .*\n  color: "#cccccc"\n' +
      'After calling, read deployment.status from the response (or poll get_categorization) to know when the project-wide deploy finished; the targeted crawl itself is already done.',
    inputSchema: {
      type: 'object',
      properties: {
        crawl_id: { type: 'integer' },
        yaml: { type: 'string', description: 'The categorization rules as a YAML string (see description for the format).' },
        deploy_to_project: { type: 'boolean', default: true, description: 'Also re-categorize the other crawls of the project (async). Set false to apply only to this crawl.' },
      },
      required: ['crawl_id', 'yaml'],
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
    case 'get_crawl_status':
      return callApi(token, 'GET', `/crawls/${encodeURIComponent(args.crawl_id)}/status`);
    case 'stop_crawl':
      return callApi(token, 'POST', `/crawls/${encodeURIComponent(args.crawl_id)}/stop`);
    case 'start_crawl':
      return callApi(token, 'POST', `/crawls/${encodeURIComponent(args.crawl_id)}/start`);
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
    case 'get_categorization':
      return callApi(token, 'GET', `/crawls/${encodeURIComponent(args.crawl_id)}/categorization`);
    case 'set_categorization':
      return callApi(token, 'PUT', `/crawls/${encodeURIComponent(args.crawl_id)}/categorization`, {
        body: { yaml: args.yaml, deploy_to_project: args.deploy_to_project },
      });
    case 'list_schedules':
      return callApi(token, 'GET', '/schedules');
    case 'get_schedule':
      return callApi(token, 'GET', `/projects/${encodeURIComponent(args.project_id)}/schedule`);
    case 'set_schedule':
      return callApi(token, 'PUT', `/projects/${encodeURIComponent(args.project_id)}/schedule`, {
        body: {
          template_crawl_id: args.template_crawl_id,
          frequency: args.frequency,
          hour: args.hour,
          minute: args.minute,
          days_of_week: args.days_of_week,
          day_of_month: args.day_of_month,
          enabled: args.enabled,
        },
      });
    case 'toggle_schedule':
      return callApi(token, 'PATCH', `/projects/${encodeURIComponent(args.project_id)}/schedule`, { body: { enabled: args.enabled } });
    case 'delete_schedule':
      return callApi(token, 'DELETE', `/projects/${encodeURIComponent(args.project_id)}/schedule`);
    default:
      return null;
  }
}
