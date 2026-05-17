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
        // Parse & validate input BEFORE we open the SSE stream — easier to
        // surface a clean JSON error here than to send an "error" SSE event
        // on a connection that's already half-open.
        $crawlId  = (int)$request->get('crawl_id', 0);
        $messages = $request->get('messages', []);

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

        // AI configuration
        $apiKey = (string)AppSettings::get('ai.gemini.api_key');
        $model  = (string)AppSettings::get('ai.gemini.model');
        if ($apiKey === '' || $model === '') {
            $this->error('AI provider is not configured. Ask an admin to set it up in Settings.', 400);
            return;
        }

        // === Switch to SSE mode ===
        // From here on, output is text/event-stream, NOT JSON.
        $this->startSseResponse();

        $systemPrompt = DrBriefPrompt::build($crawl);
        $agent = new ChatAgent();

        $totalIn = 0;
        $totalOut = 0;
        $totalToolCalls = 0;
        $finalError = null;
        $sawDone = false;

        try {
            foreach ($agent->run($apiKey, $model, $systemPrompt, $messages, $crawl) as $event) {
                $this->sendSseEvent($event['event'], $event['data'] ?? []);
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
            $this->sendSseEvent('error', ['message' => $finalError]);
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
