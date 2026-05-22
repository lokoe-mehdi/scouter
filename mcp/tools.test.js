/**
 * Unit tests for the MCP tool catalog + dispatch logic (node:test, zero deps).
 * global fetch is mocked to capture the request the dispatcher would send,
 * so we assert URL/method/headers/body without a live API.
 *
 * Run: node --test   (from mcp/)
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { TOOLS, dispatch, API_BASE } from './tools.js';

/** Install a fake fetch that records the call and returns `response`. */
function mockFetch(response = { ok: true, status: 200, jsonText: '{"data":[]}' }) {
  const calls = [];
  global.fetch = async (url, opts = {}) => {
    calls.push({ url, opts });
    return {
      ok: response.ok,
      status: response.status,
      text: async () => response.jsonText,
    };
  };
  return calls;
}

test('exposes exactly the expected tools', () => {
  const names = TOOLS.map((t) => t.name).sort();
  assert.deepEqual(names, ['create_crawl', 'get_crawl', 'get_crawl_schema', 'get_page_content', 'get_page_html', 'list_crawls', 'list_projects', 'run_sql']);
});

test('every tool has a description and an object input schema', () => {
  for (const t of TOOLS) {
    assert.ok(typeof t.description === 'string' && t.description.length > 0, `${t.name} description`);
    assert.equal(t.inputSchema.type, 'object', `${t.name} schema type`);
  }
});

test('run_sql declares crawl_id + query as required', () => {
  const runSql = TOOLS.find((t) => t.name === 'run_sql');
  assert.deepEqual(runSql.inputSchema.required, ['crawl_id', 'query']);
});

test('list_projects → GET /projects with limit & offset query', async () => {
  const calls = mockFetch();
  await dispatch('Bearer sctr_abc', 'list_projects', { limit: 10, offset: 20 });
  assert.equal(calls.length, 1);
  assert.equal(calls[0].opts.method, 'GET');
  assert.equal(calls[0].url, `${API_BASE}/projects?limit=10&offset=20`);
  assert.equal(calls[0].opts.headers.Authorization, 'Bearer sctr_abc');
});

test('list_projects omits empty/undefined query params', async () => {
  const calls = mockFetch();
  await dispatch('Bearer x', 'list_projects', {});
  assert.equal(calls[0].url, `${API_BASE}/projects`);
});

test('list_crawls → GET /projects/{id}/crawls', async () => {
  const calls = mockFetch();
  await dispatch('Bearer x', 'list_crawls', { project_id: 42, limit: 5 });
  assert.equal(calls[0].url, `${API_BASE}/projects/42/crawls?limit=5`);
  assert.equal(calls[0].opts.method, 'GET');
});

test('get_crawl and get_crawl_schema hit the right paths', async () => {
  let calls = mockFetch();
  await dispatch('Bearer x', 'get_crawl', { crawl_id: 7 });
  assert.equal(calls[0].url, `${API_BASE}/crawls/7`);

  calls = mockFetch();
  await dispatch('Bearer x', 'get_crawl_schema', { crawl_id: 7 });
  assert.equal(calls[0].url, `${API_BASE}/crawls/7/schema`);
});

test('get_page_content → GET /crawls/{id}/content?url=… (url encoded)', async () => {
  const calls = mockFetch();
  const url = 'https://ex.com/a b?x=1';
  await dispatch('Bearer x', 'get_page_content', { crawl_id: 5, url });
  assert.equal(calls[0].opts.method, 'GET');
  assert.equal(calls[0].url, `${API_BASE}/crawls/5/content?` + new URLSearchParams({ url }).toString());
});

test('get_page_html → GET /crawls/{id}/html with url + default max_chars', async () => {
  const calls = mockFetch();
  await dispatch('Bearer x', 'get_page_html', { crawl_id: 7, url: 'https://ex.com/p' });
  assert.equal(calls[0].opts.method, 'GET');
  assert.equal(calls[0].url, `${API_BASE}/crawls/7/html?` + new URLSearchParams({ url: 'https://ex.com/p', max_chars: '50000' }).toString());
});

test('create_crawl → POST /crawls with { config } body', async () => {
  const calls = mockFetch();
  const config = { general: { start: 'https://www.website.tld/', crawl_type: 'spider' } };
  await dispatch('Bearer x', 'create_crawl', { config });
  assert.equal(calls[0].opts.method, 'POST');
  assert.equal(calls[0].url, `${API_BASE}/crawls`);
  assert.deepEqual(JSON.parse(calls[0].opts.body), { config });
});

test('run_sql → POST /crawls/{id}/query with JSON body', async () => {
  const calls = mockFetch();
  await dispatch('Bearer tok', 'run_sql', { crawl_id: 9, query: 'SELECT url FROM pages', page: 2, page_size: 250, count: false });
  const c = calls[0];
  assert.equal(c.opts.method, 'POST');
  assert.equal(c.url, `${API_BASE}/crawls/9/query`);
  assert.equal(c.opts.headers['Content-Type'], 'application/json');
  assert.deepEqual(JSON.parse(c.opts.body), { query: 'SELECT url FROM pages', page: 2, page_size: 250, count: false });
});

test('forwards no Authorization header when token is absent', async () => {
  const calls = mockFetch();
  await dispatch(undefined, 'list_projects', {});
  assert.equal(calls[0].opts.headers.Authorization, undefined);
});

test('surfaces a non-OK API response (ok:false) without throwing', async () => {
  mockFetch({ ok: false, status: 401, jsonText: '{"success":false,"error":"Invalid or missing API token."}' });
  const res = await dispatch('Bearer bad', 'list_projects', {});
  assert.equal(res.ok, false);
  assert.equal(res.status, 401);
  assert.equal(res.data.error, 'Invalid or missing API token.');
});

test('returns null for an unknown tool', async () => {
  mockFetch();
  const res = await dispatch('Bearer x', 'does_not_exist', {});
  assert.equal(res, null);
});
