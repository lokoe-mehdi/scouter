<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Settings\AppSettings;
use App\AI\ChatAgent;
use App\AI\DrBriefPrompt;
use PDO;

/**
 * Dr. Brief chat endpoint — Server-Sent Events streaming.
 *
 * POST /api/dr-brief/chat
 *   body: { crawl_id: int, messages: [{role, content}, ...] }
 *   response: text/event-stream
 *
 * Conversations are NOT persisted server-side — the browser owns the
 * `messages` array and sends the full history on each call. We only log
 * a minimal row per turn (no message content) for rate-limit + cost
 * tracking in `ai_chat_runs`.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class DrBriefController extends Controller
{
    private const MAX_MESSAGES_IN_HISTORY = 30;
    private const MAX_MESSAGE_LENGTH = 4000;

    private PDO $db;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Tracks whether we've already started writing SSE headers — past that
     * point a thrown exception can't be returned as JSON anymore (headers
     * already sent), so we surface it as an SSE `error` event instead.
     */
    private bool $sseStarted = false;

    public function chat(Request $request): void
    {
        try {
            $this->doChat($request);
        } catch (\Throwable $e) {
            $msg = 'AI chat failed: ' . $e->getMessage();
            error_log('[DrBrief] ' . $msg . "\n" . $e->getTraceAsString());
            if ($this->sseStarted) {
                $this->sendSseEvent('error', ['message' => $msg]);
                exit;
            }
            $this->error($msg, 500);
        }
    }

    private function doChat(Request $request): void
    {
        // === Abort handling ===
        // Take control of user-abort handling so we can cleanup gracefully
        // (log the partial turn, close the curl to OpenRouter, …) when the
        // user clicks Stop or closes the tab. Without this, PHP-FPM would
        // either die abruptly at the next echo (default `ignore_user_abort(false)`)
        // or keep running until the upstream finished — burning a worker
        // and tokens for nothing. We set it to TRUE here, then poll
        // connection_aborted() after every SSE flush below : as soon as
        // the client is gone, we break the loop, log, and exit.
        ignore_user_abort(true);


        // Parse & validate input BEFORE we open the SSE stream — easier to
        // surface a clean JSON error here than to send an "error" SSE event
        // on a connection that's already half-open.
        $crawlId     = (int)$request->get('crawl_id', 0);
        $messages    = $request->get('messages', []);
        // Page context — DOM snapshot of what the user is currently viewing.
        // Optional but recommended : lets Dr. Brief answer "summarize this"
        // type questions without an extra round-trip via run_sql.
        $pageContext = (string)$request->get('page_context', '');
        if (mb_strlen($pageContext) > 16000) {
            $pageContext = mb_substr($pageContext, 0, 16000) . "\n… (truncated)";
        }

        if ($crawlId <= 0)         { $this->error('crawl_id is required', 400); return; }
        if (!is_array($messages))  { $this->error('messages must be an array', 400); return; }
        if (empty($messages))      { $this->error('messages cannot be empty', 400); return; }
        if (count($messages) > self::MAX_MESSAGES_IN_HISTORY) {
            $this->error('Too many messages in history (max ' . self::MAX_MESSAGES_IN_HISTORY . ')', 400);
            return;
        }
        foreach ($messages as $m) {
            if (!is_array($m) || !isset($m['role'], $m['content'])) {
                $this->error('Each message must have role + content', 400);
                return;
            }
            if (!in_array($m['role'], ['user', 'assistant'], true)) {
                $this->error('Invalid role: ' . $m['role'], 400);
                return;
            }
            if (mb_strlen((string)$m['content']) > self::MAX_MESSAGE_LENGTH) {
                $this->error('Message too long (max ' . self::MAX_MESSAGE_LENGTH . ' chars)', 400);
                return;
            }
        }
        // Last message must be from the user (the one we're answering).
        if (($messages[count($messages) - 1]['role'] ?? '') !== 'user') {
            $this->error('Last message must be from user', 400);
            return;
        }

        // Resolve crawl + authorize
        $crawl = CrawlDatabase::getCrawlById($crawlId);
        if (!$crawl) { $this->error('Crawl not found', 404); return; }

        $crawlPath = (string)($crawl->path ?? '');
        if ($crawlPath !== '') {
            $this->auth->requireCrawlAccess($crawlPath, true);
        } else {
            $this->auth->requireCrawlAccessById($crawlId, true);
        }

        // === CRITICAL : release the PHP session lock ===
        //
        // PHP's default session handler holds an EXCLUSIVE FILE LOCK on the
        // session file from `session_start()` until the script ends. While
        // this lock is held, every other authenticated request from the SAME
        // user (other tabs, sql explorer, project page, navigation, …) calls
        // session_start() and SITS WAITING for the lock — for the entire
        // duration of this chat turn, which can be minutes with tool calls.
        //
        // The result, before this fix : a single in-flight chat froze the
        // whole app for the user (other tabs spin forever). The fix is
        // standard PHP : auth is already verified above, we don't need to
        // write back to the session, so we can close it immediately and
        // release the lock. Session data stays available in $_SESSION for
        // read access if the rest of the request needs it.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // AI configuration — Dr. Brief uses the "strong" model because it
        // needs tool calling (run_sql, get_page_headings) and multi-turn
        // reasoning. The settings UI already filters this dropdown to
        // models that advertise `tools` in supported_parameters.
        $apiKey = (string)AppSettings::get('ai.openrouter.api_key');
        $model  = (string)AppSettings::get('ai.openrouter.model_strong');
        if ($apiKey === '' || $model === '') {
            $this->error('AI provider is not configured. Ask an admin to set it up in Settings.', 400);
            return;
        }

        // === Switch to SSE mode ===
        // From here on, output is text/event-stream, NOT JSON.
        $this->startSseResponse();

        // Current UI language — drives the model's reply language. I18n is
        // initialised by web/api/index.php on boot so it's always available.
        $uiLang = null;
        if (class_exists('\\I18n')) {
            try { $uiLang = \I18n::getInstance()->getLang(); } catch (\Throwable $e) { $uiLang = null; }
        }

        // Recent crawls of the same project — feeds the multi-crawl section
        // of the prompt so Dr. Brief can compare current vs past via the
        // `pages@<id>` syntax. SqlExecutor already enforces same-project
        // boundary on those @id references, so we're safe.
        $projectCrawls = $this->fetchRecentProjectCrawls((int)$crawl->project_id, 20);

        $systemPrompt = DrBriefPrompt::build(
            $crawl,
            $pageContext !== '' ? $pageContext : null,
            $uiLang,
            $projectCrawls
        );
        $agent = new ChatAgent();

        $totalIn = 0;
        $totalOut = 0;
        $totalToolCalls = 0;
        $finalError = null;
        $sawDone = false;

        $aborted = false;
        try {
            foreach ($agent->run($apiKey, $model, $systemPrompt, $messages, $crawl) as $event) {
                $this->sendSseEvent($event['event'], $event['data'] ?? []);
                // After every flush, check if the client is still there.
                // connection_aborted() returns true only after a write
                // attempt has failed — which sendSseEvent just did — so
                // this is the reliable detection point. If gone, break
                // out of the agent loop : the foreach itself will then
                // unwind, closing the active curl to OpenRouter as the
                // generator is garbage-collected, and the worker is freed.
                if (connection_aborted()) {
                    $aborted    = true;
                    $finalError = 'client_disconnected';
                    error_log('[DrBrief] Client disconnected mid-turn, aborting agent loop.');
                    break;
                }
                if ($event['event'] === 'done') {
                    $totalIn  = (int)($event['data']['input_tokens']  ?? 0);
                    $totalOut = (int)($event['data']['output_tokens'] ?? 0);
                    $totalToolCalls = (int)($event['data']['tool_calls'] ?? 0);
                    $sawDone = true;
                } elseif ($event['event'] === 'error') {
                    $finalError = (string)($event['data']['message'] ?? 'unknown error');
                }
            }
        } catch (\Throwable $e) {
            $finalError = $e->getMessage();
            if (!connection_aborted()) {
                $this->sendSseEvent('error', ['message' => $finalError]);
            }
        }

        // Audit log (no content)
        $this->logRun($crawlId, $model, $totalIn, $totalOut, $totalToolCalls, $sawDone && $finalError === null, $finalError);

        // Close the stream cleanly so EventSource.onmessage stops re-trying.
        exit;
    }

    // -------------------------------------------------------------------------
    // SSE helpers
    // -------------------------------------------------------------------------

    private function startSseResponse(): void
    {
        // Discard any output that PHP buffered before us (warnings, etc.)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');      // disable nginx proxy buffering
        header('Connection: keep-alive');
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ob_implicit_flush(true);
        // First flush to commit headers immediately.
        echo "\n";
        @ob_flush();
        @flush();
        $this->sseStarted = true;
    }

    private function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        @flush();
    }

    // -------------------------------------------------------------------------
    // Project crawls — used by the multi-crawl prompt section
    // -------------------------------------------------------------------------

    /**
     * Most recent crawls of a project that have usable data
     * (terminal status, not currently being deleted). Capped to N because
     * the AI doesn't need more than that to answer comparison questions —
     * and keeps the prompt small.
     *
     * @return array<int, object>
     */
    private function fetchRecentProjectCrawls(int $projectId, int $limit = 20): array
    {
        if ($projectId <= 0) return [];
        try {
            $stmt = $this->db->prepare("
                SELECT id, started_at, status, urls, crawled, depth_max
                FROM crawls
                WHERE project_id = :pid
                  AND status IN ('finished', 'stopped')
                ORDER BY started_at DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit,     PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];
        } catch (\Throwable $e) {
            error_log('[DrBrief] fetchRecentProjectCrawls failed: ' . $e->getMessage());
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Audit log (no rate limit anymore — removed per user request)
    // -------------------------------------------------------------------------

    private function logRun(int $crawlId, string $model, int $inTok, int $outTok, int $toolCalls, bool $success, ?string $error): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_chat_runs
                    (user_id, crawl_id, model, input_tokens, output_tokens, tool_calls, success, error_message)
                VALUES
                    (:uid, :cid, :model, :in, :out, :tc, :ok, :err)
            ");
            $stmt->execute([
                ':uid'   => $this->userId,
                ':cid'   => $crawlId,
                ':model' => $model,
                ':in'    => $inTok,
                ':out'   => $outTok,
                ':tc'    => $toolCalls,
                ':ok'    => $success ? 1 : 0,
                ':err'   => $error,
            ]);
        } catch (\Throwable $e) {
            error_log('[DrBrief] audit log failed: ' . $e->getMessage());
        }
    }
}
