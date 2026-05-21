/**
 * Scouter MCP server — remote (Streamable HTTP) adapter over the public REST
 * API. Each MCP tool maps 1:1 to a /api/v1 endpoint. Authentication is pure
 * pass-through: the `Authorization: Bearer sctr_…` header sent by the MCP
 * client is forwarded verbatim to the API, so the server holds no secrets and
 * acts strictly as the calling user.
 *
 * Stateless mode: a fresh Server + transport is built per POST (no session
 * store, no server-initiated SSE streams) — simplest correct shape for plain
 * request/response tools.
 */
import express from 'express';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import {
  ListToolsRequestSchema,
  CallToolRequestSchema,
  ListPromptsRequestSchema,
  GetPromptRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { fileURLToPath } from 'node:url';
import { API_BASE, TOOLS, dispatch } from './tools.js';
import { INSTRUCTIONS, PROMPTS } from './seo-playbook.js';

const PORT = Number(process.env.PORT || 3000);

function buildServer(token) {
  const server = new Server(
    { name: 'scouter-mcp', version: '1.0.0' },
    {
      // `instructions` ships the SEO playbook to every client at initialize.
      instructions: INSTRUCTIONS,
      capabilities: { tools: {}, prompts: {} },
    },
  );

  server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: TOOLS }));

  // Invocable workflows (audit / synthese).
  server.setRequestHandler(ListPromptsRequestSchema, async () => ({
    prompts: PROMPTS.map((p) => ({ name: p.name, description: p.description, arguments: p.arguments })),
  }));
  server.setRequestHandler(GetPromptRequestSchema, async (req) => {
    const prompt = PROMPTS.find((p) => p.name === req.params.name);
    if (!prompt) throw new Error(`Unknown prompt: ${req.params.name}`);
    return { description: prompt.description, messages: prompt.build(req.params.arguments || {}) };
  });

  server.setRequestHandler(CallToolRequestSchema, async (req) => {
    const { name, arguments: args = {} } = req.params;
    let res;
    try {
      res = await dispatch(token, name, args);
    } catch (e) {
      return { content: [{ type: 'text', text: `Request failed: ${e.message}` }], isError: true };
    }
    if (res === null) {
      return { content: [{ type: 'text', text: `Unknown tool: ${name}` }], isError: true };
    }
    const text = typeof res.data === 'string' ? res.data : JSON.stringify(res.data, null, 2);
    return { content: [{ type: 'text', text }], isError: !res.ok };
  });

  return server;
}

const app = express();
app.use(express.json({ limit: '2mb' }));

app.get('/healthz', (_req, res) => res.json({ ok: true, api: API_BASE }));

app.post('/mcp', async (req, res) => {
  const token = req.headers['authorization'];

  // No credentials → trigger OAuth discovery (RFC 9728): point the client at the
  // protected-resource metadata so claude.ai can run the auth flow. Claude Code
  // / Desktop configure the Bearer header directly and skip this branch.
  if (!token) {
    const proto = req.headers['x-forwarded-proto'] || 'https';
    const host = req.headers['x-forwarded-host'] || req.headers['host'];
    res.set('WWW-Authenticate', `Bearer resource_metadata="${proto}://${host}/.well-known/oauth-protected-resource"`);
    return res.status(401).json({ jsonrpc: '2.0', error: { code: -32001, message: 'Authentication required.' }, id: null });
  }

  const server = buildServer(token);
  const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
  res.on('close', () => { try { transport.close(); server.close(); } catch { /* ignore */ } });
  try {
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
  } catch (e) {
    if (!res.headersSent) {
      res.status(500).json({ jsonrpc: '2.0', error: { code: -32603, message: String(e?.message || e) }, id: null });
    }
  }
});

// Stateless mode: no server-push SSE streams nor session teardown to handle.
const methodNotAllowed = (_req, res) =>
  res.status(405).json({ jsonrpc: '2.0', error: { code: -32000, message: 'Method not allowed.' }, id: null });
app.get('/mcp', methodNotAllowed);
app.delete('/mcp', methodNotAllowed);

// Only start listening when run directly (not when imported, e.g. by tests).
const isMain = process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1];
if (isMain) {
  app.listen(PORT, () => {
    console.log(`Scouter MCP server listening on :${PORT} → API ${API_BASE}`);
  });
}

export { app, buildServer };
