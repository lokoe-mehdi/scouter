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
    description: "Get a crawl's metadata: domain, status, URL count, start/finish dates.",
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
    case 'run_sql':
      return callApi(token, 'POST', `/crawls/${encodeURIComponent(args.crawl_id)}/query`, {
        body: { query: args.query, page: args.page, page_size: args.page_size, count: args.count },
      });
    default:
      return null;
  }
}
