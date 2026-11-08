<?php

namespace Tests\Support;

use App\Gsc\GscIngestor;

/** Exercise the real ingestion pipeline with deterministic Google responses. */
class GscFixtureIngestor extends GscIngestor
{
    public array $writes = [];
    public array $requests = [];

    public function __construct(private array $responses, private bool $persist = false, int $projectId = 1)
    {
        parent::__construct($projectId, 'https://example.test/', 'fixture-token');
    }

    protected function queryRows(string $date, array $dimensions, string $dataState): array
    {
        $key = implode(',', $dimensions);
        $this->requests[] = $key;
        $value = $this->responses[$key] ?? [];
        if ($value instanceof \Throwable) {
            throw $value;
        }
        return $value;
    }

    protected function insert(string $table, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $this->writes[$table] = array_merge($this->writes[$table] ?? [], $rows);
        if ($this->persist) {
            parent::insert($table, $rows);
        }
    }
}
