<?php

/**
 * Guards the sitemap post-process log format: every line must follow the same
 * ANSI pattern as the other PostProcessor steps (Inlinks, Pagerank, ...), i.e.
 *
 *   "\r \033[32m Sitemap analysis \033[0m : \033[<color>m<status>\033[0m<padding>[\n]"
 *
 * If someone later refactors PostProcessor::sitemapAnalysis() and accidentally
 * breaks the format (extra newlines, wrong colors, missing carriage return),
 * these tests fail loudly.
 */

describe('Sitemap log format', function () {

    $source = file_get_contents(__DIR__ . '/../../app/Analysis/PostProcessor.php');

    // Pattern: \r followed by green step name, then colored status, padded with spaces
    // The status color must be cyan (\033[36m) for progress or yellow (\033[33m) for no-op/skipped
    $linePattern = '/echo\s+"\\\\r\s+\\\\033\[32m\s+Sitemap analysis\s+\\\\033\[0m\s*:\s*\\\\033\[(?:36|33)m[^"]*\\\\033\[0m/';

    it('uses the Inlinks/Pagerank line format for every echo in sitemapAnalysis', function () use ($source, $linePattern) {
        // Locate the sitemapAnalysis method body
        $start = strpos($source, 'public function sitemapAnalysis');
        expect($start)->not->toBeFalse();

        // Take the method body up to the next "public function" or "private function"
        $rest = substr($source, $start);
        $end = strpos($rest, "\n    /**\n     *", 50);
        if ($end === false) {
            $end = strlen($rest);
        }
        $body = substr($rest, 0, $end);

        // Find every "Sitemap analysis" echo line and check it matches the pattern
        preg_match_all('/echo\s+"[^"]*Sitemap analysis[^"]*";/', $body, $matches);
        expect($matches[0])->not->toBeEmpty();

        foreach ($matches[0] as $line) {
            expect($line)->toMatch($linePattern);
        }
    });

    it('uses carriage return (\\r) on every sitemap log line so they overwrite in place', function () use ($source) {
        $start = strpos($source, 'public function sitemapAnalysis');
        $rest  = substr($source, $start);
        preg_match_all('/echo\s+"[^"]*Sitemap analysis[^"]*";/', $rest, $matches);
        expect($matches[0])->not->toBeEmpty();
        foreach ($matches[0] as $line) {
            expect($line)->toStartWith('echo "\\r');
        }
    });

    it('ends only the final/skip/no-op messages with a newline (\\n)', function () use ($source) {
        $start = strpos($source, 'public function sitemapAnalysis');
        $rest  = substr($source, $start);
        preg_match_all('/echo\s+"\\\\r[^"]*Sitemap analysis[^"]*\\\\n"/', $rest, $matches);

        // Each line ending in \n must be either the final summary or a terminal status
        // (skipped / no URLs found / deferred). Acceptable markers in the source:
        //  - "skipped"        → caught exception path
        //  - "no URLs found"  → empty sitemap result
        //  - "deferred"       → crawl not finished (stopped/error/failed) → early return
        //  - "$summary"       → final tally interpolation
        foreach ($matches[0] as $line) {
            $isFinal = strpos($line, 'skipped') !== false
                    || strpos($line, 'no URLs found') !== false
                    || strpos($line, 'deferred') !== false
                    || strpos($line, '$summary') !== false;
            expect($isFinal)->toBeTrue();
        }
    });

    it('is registered as the last step of PostProcessor::run()', function () use ($source) {
        // The $steps array order matters — sitemapAnalysis must come AFTER
        // all the other post-process steps so its log line appears at the end.
        preg_match('/\$steps\s*=\s*\[(.*?)\];/s', $source, $m);
        expect($m[1] ?? '')->toContain("'sitemapAnalysis'");
        $trimmed = preg_replace('/\s+/', '', $m[1] ?? '');
        expect($trimmed)->toEndWith("'sitemapAnalysis',");
    });
});
