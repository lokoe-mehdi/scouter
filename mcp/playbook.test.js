/**
 * Tests for the bundled SEO playbook (instructions + prompts). node:test, no deps.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { INSTRUCTIONS, PROMPTS } from './seo-playbook.js';

test('instructions ship the key sections', () => {
  assert.ok(INSTRUCTIONS.length > 1000, 'instructions should be substantial');
  for (const marker of ['SITE OVERVIEW', 'canonical', 'crawled = true AND in_crawl = true', 'run_sql', 'rel="nofollow"', 'Category is the unit of analysis', 'DEPTH distribution per category', 'PAGERANK distribution per category']) {
    assert.ok(INSTRUCTIONS.includes(marker), `instructions should mention "${marker}"`);
  }
});

test('exposes the audit and synthese prompts', () => {
  const names = PROMPTS.map((p) => p.name).sort();
  assert.deepEqual(names, ['audit', 'synthese']);
  for (const p of PROMPTS) {
    assert.ok(typeof p.description === 'string' && p.description.length > 0);
    assert.ok(Array.isArray(p.arguments));
  }
});

test('prompt.build injects the crawl id when provided', () => {
  const audit = PROMPTS.find((p) => p.name === 'audit');
  const msgs = audit.build({ crawl_id: '542' });
  assert.equal(msgs[0].role, 'user');
  assert.ok(msgs[0].content.text.includes('542'));
});

test('prompt.build falls back to "latest finished crawl" without an id', () => {
  const synth = PROMPTS.find((p) => p.name === 'synthese');
  const msgs = synth.build({});
  assert.ok(/latest finished crawl/i.test(msgs[0].content.text));
});
