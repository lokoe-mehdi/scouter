<?php

namespace App\Storage;

/**
 * Blob store for per-page HTML kept OUTSIDE the database.
 *
 * The crawler (Go) writes each page's gzip-compressed HTML here under the key
 * html/<crawl_id>/<page_id>.gz; the web app, API, MCP and chatbot read it back.
 * Two backends implement this contract — {@see S3Storage} and
 * {@see LocalStorage} — selected from the environment by {@see Storage::instance}.
 *
 * @package    Scouter
 * @subpackage Storage
 */
interface StorageInterface
{
    /**
     * Return the raw object bytes at $key, or null when it does not exist.
     */
    public function get(string $key): ?string;

    /**
     * Write $data at $key, overwriting any existing object.
     */
    public function put(string $key, string $data): bool;

    /**
     * Upload a local file at $path to $key WITHOUT loading it into memory
     * (streamed). Used for large CSV exports. Returns false on failure.
     */
    public function putFile(string $key, string $path, string $contentType = ''): bool;

    /**
     * Return a time-limited, directly-downloadable URL for $key, or null when the
     * backend can't produce one (local disk) — the caller then streams via the
     * app. $responseHeaders may set response-content-disposition/-type so the
     * object downloads as an attachment with a friendly name.
     *
     * @param array<string,string> $responseHeaders
     */
    public function presignedGetUrl(string $key, int $expirySeconds, array $responseHeaders = []): ?string;

    /**
     * Delete every object whose key starts with $prefix. Returns the count removed.
     */
    public function deletePrefix(string $prefix): int;

    /** Backend name ("s3" or "local"), for logging/diagnostics. */
    public function kind(): string;
}
