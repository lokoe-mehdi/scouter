<?php

namespace App\Api;

/**
 * Extracts the readable content of a crawled page from its stored HTML:
 * the title, the ordered h1..h6 tree, and the visible text. Same decode path
 * as the Dr. Brief tools (HtmlTool / HeadingsTool) — the HTML blob is
 * base64-encoded, gzip-deflated bytes.
 *
 * Kept transport-agnostic and pure (operates on an HTML string) so it can be
 * unit-tested and reused by the public API endpoint and any future caller.
 *
 * @package    Scouter
 * @subpackage Api
 */
class PageContent
{
    /** Safety cap on the returned visible text (chars). */
    public const MAX_TEXT_CHARS = 200000;

    /** Block-level tags after which we inject a newline so the text stays readable. */
    private const BLOCK_TAGS = ['p','div','li','ul','ol','section','article','header','footer','tr','table','h1','h2','h3','h4','h5','h6','br','blockquote','pre'];

    /**
     * Decode the stored HTML blob (base64 → gzinflate). Falls back to raw
     * base64 for legacy uncompressed rows. Returns null when unusable.
     */
    public static function decode(?string $stored): ?string
    {
        if (!$stored) return null;
        $decoded = base64_decode($stored, true);
        if ($decoded === false) return null;
        $inflated = @gzinflate($decoded);
        return $inflated !== false ? $inflated : $decoded;
    }

    /**
     * Extract { title, headings[], text, word_count, truncated } from raw HTML.
     *
     * @return array{title:string, headings:array<int,array{level:int,text:string}>, text:string, word_count:int, truncated:bool}
     */
    public static function extract(string $rawHtml): array
    {
        $clean = self::stripNoise($rawHtml);

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $clean);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);

        // Title (from <title>).
        $title = '';
        $titleNodes = $xpath->query('//title');
        if ($titleNodes && $titleNodes->length > 0) {
            $title = self::collapse($titleNodes->item(0)->textContent);
        }

        // Ordered h1..h6.
        $headings = [];
        $hNodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        if ($hNodes !== false) {
            foreach ($hNodes as $node) {
                $text = self::collapse($node->textContent);
                if ($text === '') continue;
                $headings[] = ['level' => (int)substr($node->nodeName, 1), 'text' => $text];
            }
        }

        // Visible text — prefer <body>, fall back to the whole document.
        $bodyNodes = $xpath->query('//body');
        $source = ($bodyNodes !== false && $bodyNodes->length > 0)
            ? $bodyNodes->item(0)->textContent
            : $dom->textContent;
        $text = self::normalizeText((string)$source);

        $truncated = false;
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
            $truncated = true;
        }

        $wordCount = $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));

        return [
            'title'      => $title,
            'headings'   => $headings,
            'text'       => $text,
            'word_count' => $wordCount,
            'truncated'  => $truncated,
        ];
    }

    /**
     * Strip everything that isn't visible text: comments, <script> (incl.
     * JSON-LD — not visible), <style>, <svg>, <noscript>, <head>. Then inject a
     * newline after block-level tags so the extracted text keeps its structure.
     */
    private static function stripNoise(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style\s*>#is', '', $html);
        $html = preg_replace('#<svg\b[^>]*>.*?</svg\s*>#is', '', $html);
        $html = preg_replace('#<noscript\b[^>]*>.*?</noscript\s*>#is', '', $html);

        // Newline after block elements (so textContent doesn't glue paragraphs).
        $tags = implode('|', self::BLOCK_TAGS);
        $html = preg_replace('#</?(' . $tags . ')(\s[^>]*)?/?>#i', "$0\n", (string)$html);

        return (string)$html;
    }

    /** Collapse internal whitespace of a short string (titles, headings). */
    private static function collapse(string $s): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Normalize the body text: collapse spaces/tabs, trim each line, drop empty
     * lines, and cap consecutive blank lines — readable without being a single
     * glued blob.
     */
    private static function normalizeText(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
        $lines = array_map('trim', explode("\n", (string)$s));
        $lines = array_filter($lines, static fn($l) => $l !== '');
        return trim(implode("\n", $lines));
    }
}
