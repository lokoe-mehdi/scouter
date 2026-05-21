<?php

namespace App\AI\Prompts;

/**
 * Build the system+user prompts for the Bulk AI Generator and validate the
 * model's JSON response.
 *
 * The output contract is a strict JSON object the model MUST return :
 *   {"results": [
 *      {"page_id": "abc12345", "title_proposal": "…", "score_quality": 78},
 *      …
 *   ]}
 *
 * Each entry must include the `page_id` AND every item the user requested,
 * with the EXPECTED TYPE (string/number/boolean). We force the type via the
 * system prompt + `response_format: {type: 'json_object'}` on OpenRouter,
 * and verify on the way back — any URL whose payload mistypes a field is
 * marked failed and routed to the 1-by-1 retry pass.
 *
 * @package    Scouter
 * @subpackage AI\Prompts
 */
class BulkGeneratePrompt
{
    /**
     * Build the [system, user] messages for one batch.
     *
     * @param array $items           [{name, type, note}, …] as configured in the wizard
     * @param string $userPromptTpl  user-written prompt template (can mention {url}, {title}, …)
     * @param array $contextBatch    list of context entries from ContextBuilder
     * @return array{system:string, user:string}
     */
    public static function build(array $items, string $userPromptTpl, array $contextBatch): array
    {
        // ---- System prompt : role + strict typing spec --------------
        $itemsSpecLines = [];
        foreach ($items as $item) {
            $name = (string)($item['name'] ?? '');
            $type = (string)($item['type'] ?? 'text');
            $note = trim((string)($item['note'] ?? ''));
            $typeLabel = self::jsonTypeLabel($type);
            $line = "  - {$name} : {$typeLabel}";
            if ($note !== '') $line .= " — {$note}";
            $itemsSpecLines[] = $line;
        }
        $itemsSpec = implode("\n", $itemsSpecLines);

        // page_id list to remind the model NOT to invent new ids.
        $pageIds = array_map(static fn($e) => (string)($e['page_id'] ?? ''), $contextBatch);
        $pageIdsList = '"' . implode('", "', $pageIds) . '"';

        $system = <<<SYSTEM
You generate structured content for a list of web pages. Respond ONLY with
a JSON object matching this exact schema :

{"results": [
  {"page_id": "<one of the input ids>", <fields>},
  …
]}

For every input page, you MUST output exactly one object. Each object MUST
include "page_id" (echoing the input id verbatim) plus the following fields
with the EXACT types listed :

{$itemsSpec}

Strict rules — non-negotiable :
  - The "results" array MUST have the same length as the input list.
  - Every "page_id" MUST be one of the input ids ({$pageIdsList}). Never
    invent new ids, never skip an input page.
  - Respect the JSON type. A `boolean` field MUST be `true` or `false`
    (lowercase, no quotes). A `number` field MUST be an unquoted numeric
    literal (e.g. `78` or `3.5`, NEVER `"78"`).
  - No prose, no markdown, no explanation outside the JSON object.
SYSTEM;

        // ---- User prompt : task description + batch of inputs -------
        $jsonInputs = json_encode(
            $contextBatch,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        $user = trim($userPromptTpl)
              . "\n\n=== Input pages (process EACH one) ===\n"
              . $jsonInputs;

        return ['system' => $system, 'user' => $user];
    }

    /**
     * Parse + validate the model's response. Returns one of :
     *   ['ok' => true,  'results' => [page_id => casted_values]]
     *   ['ok' => false, 'error'   => string]
     *
     * Each casted_values is an associative array {field_name => typed_value}
     * ready to be merged into pages.generation via `||` JSONB.
     *
     * @param string $rawJson  the `content` from the model's reply
     * @param array  $items    same spec passed to build()
     * @param string[] $expectedPageIds  ids that must all appear in results
     */
    public static function parseResponse(string $rawJson, array $items, array $expectedPageIds): array
    {
        $rawJson = trim($rawJson);
        // Some models still wrap in ```json ... ``` despite the system prompt — strip it.
        if (preg_match('#^```(?:json)?\s*(.*?)\s*```$#s', $rawJson, $m)) {
            $rawJson = trim($m[1]);
        }
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            return ['ok' => false, 'error' => 'Model did not return a {"results": [...]} object'];
        }

        $byPageId = [];
        foreach ($decoded['results'] as $entry) {
            if (!is_array($entry) || empty($entry['page_id'])) continue;
            $pid = (string)$entry['page_id'];
            if (!in_array($pid, $expectedPageIds, true)) continue;

            // Type-check each requested item ; abort this row if any field
            // is missing or mistyped — fallback retry will pick it up.
            $values = [];
            $rowOk  = true;
            foreach ($items as $item) {
                $name = (string)($item['name'] ?? '');
                $type = (string)($item['type'] ?? 'text');
                if (!array_key_exists($name, $entry)) { $rowOk = false; break; }
                $cast = self::castAndValidate($entry[$name], $type);
                if ($cast === null && $entry[$name] !== null) {
                    // Strict mismatch (e.g. expected number, got non-numeric string).
                    $rowOk = false;
                    break;
                }
                $values[$name] = $cast;
            }
            if ($rowOk) {
                $byPageId[$pid] = $values;
            }
        }

        return ['ok' => true, 'results' => $byPageId];
    }

    /**
     * Cast a value to the expected JSON type, return null if impossible
     * (caller treats as failure for that row).
     */
    private static function castAndValidate($value, string $type)
    {
        switch ($type) {
            case 'number':
                if (is_int($value) || is_float($value)) return $value;
                if (is_string($value) && is_numeric($value)) {
                    // Allow string-encoded numbers only if cleanly numeric.
                    return strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                return null;
            case 'boolean':
                if (is_bool($value)) return $value;
                if (is_string($value)) {
                    $low = strtolower(trim($value));
                    if ($low === 'true')  return true;
                    if ($low === 'false') return false;
                }
                return null;
            case 'text':
            default:
                if (is_string($value)) return $value;
                if (is_scalar($value)) return (string)$value;
                return null;
        }
    }

    private static function jsonTypeLabel(string $type): string
    {
        switch ($type) {
            case 'number':  return 'number (unquoted numeric, e.g. 42 or 3.5)';
            case 'boolean': return 'boolean (true or false, unquoted)';
            case 'text':
            default:        return 'string';
        }
    }
}
