<?php

use App\Gsc\GscSchema;

it('defines the same page totals and completion schemas in PHP and the Go crawler', function () {
    $php = (new ReflectionClass(GscSchema::class))->getConstant('TABLES');
    $sql = file_get_contents(__DIR__ . '/../../crawler-go/internal/db/schema.sql');
    foreach (['gsc_page_totals_daily', 'gsc_ingestion_days'] as $table) {
        preg_match('/CREATE TABLE IF NOT EXISTS scouter\.' . $table . '\s*\(.*?ORDER BY [^;]+;/s', $sql, $match);
        expect($match)->not->toBeEmpty();
        $normalize = fn(string $s) => preg_replace('/\s+/', '', rtrim($s, ';'));
        expect($normalize($match[0]))->toBe($normalize($php[$table]));
    }
});
