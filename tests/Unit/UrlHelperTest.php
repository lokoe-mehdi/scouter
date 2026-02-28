<?php

describe('URL Helpers', function () {

    it('generates consistent CRC32 hash', function () {
        $url = 'https://example.com/page';
        
        $hash1 = hash('crc32', $url, false);
        $hash2 = hash('crc32', $url, false);
        
        expect($hash1)->toBe($hash2);
        expect($hash1)->toBeString();
        expect(strlen($hash1))->toBe(8);
    });

    it('adds trailing slash to domain-only URLs', function () {
        $url = 'https://example.com';
        
        // Simuler la logique du crawler
        if (preg_match("#^https?://[^/]+$#", $url)) {
            $url = $url . "/";
        }
        
        expect($url)->toBe('https://example.com/');
    });

    it('does not add trailing slash to URLs with path', function () {
        $url = 'https://example.com/page';
        
        // Simuler la logique du crawler
        if (preg_match("#^https?://[^/]+$#", $url)) {
            $url = $url . "/";
        }
        
        expect($url)->toBe('https://example.com/page');
    });

    it('extracts domain from URL', function () {
        $url = 'https://www.example.com/page/subpage?query=1';
        
        preg_match("#https?:\/\/([^\/]+)#i", $url, $matches);
        $domain = $matches[1] ?? '';
        
        expect($domain)->toBe('www.example.com');
    });

    it('converts relative to absolute URLs', function () {
        $base = 'https://example.com/folder/page.html';
        $relative = '../other/file.html';
        
        // Fonction rel2abs simplifiée pour le test
        $rel2abs = function($base, $rel) {
            $baseParts = parse_url($base);
            $relParts = parse_url($rel);
            
            if (isset($relParts['scheme'])) {
                return $rel;
            }
            
            $scheme = $baseParts['scheme'] ?? 'https';
            $host = $baseParts['host'] ?? '';
            
            if (isset($relParts['host'])) {
                return $scheme . '://' . $relParts['host'] . ($relParts['path'] ?? '/');
            }
            
            $basePath = $baseParts['path'] ?? '/';
            $relPath = $relParts['path'] ?? '';
            
            if (strpos($relPath, '/') === 0) {
                return $scheme . '://' . $host . $relPath;
            }
            
            // Résoudre le chemin relatif
            $baseDir = dirname($basePath);
            $parts = explode('/', $baseDir . '/' . $relPath);
            $resolved = [];
            
            foreach ($parts as $part) {
                if ($part === '..') {
                    array_pop($resolved);
                } elseif ($part !== '.' && $part !== '') {
                    $resolved[] = $part;
                }
            }
            
            return $scheme . '://' . $host . '/' . implode('/', $resolved);
        };
        
        $absolute = $rel2abs($base, $relative);
        
        expect($absolute)->toBe('https://example.com/other/file.html');
    });

    it('handles .. and . in paths', function () {
        $base = 'https://example.com/a/b/c/page.html';
        $relative = '../../d/file.html';
        
        $rel2abs = function($base, $rel) {
            $baseParts = parse_url($base);
            $scheme = $baseParts['scheme'] ?? 'https';
            $host = $baseParts['host'] ?? '';
            $basePath = $baseParts['path'] ?? '/';
            
            $baseDir = dirname($basePath);
            $parts = explode('/', $baseDir . '/' . $rel);
            $resolved = [];
            
            foreach ($parts as $part) {
                if ($part === '..') {
                    array_pop($resolved);
                } elseif ($part !== '.' && $part !== '') {
                    $resolved[] = $part;
                }
            }
            
            return $scheme . '://' . $host . '/' . implode('/', $resolved);
        };
        
        $absolute = $rel2abs($base, $relative);
        
        expect($absolute)->toBe('https://example.com/a/d/file.html');
    });

    it('preserves query strings', function () {
        $url = 'https://example.com/page?param1=value1&param2=value2';
        
        $parts = parse_url($url);
        
        expect($parts['query'])->toBe('param1=value1&param2=value2');
        
        // Reconstruire l'URL
        $rebuilt = $parts['scheme'] . '://' . $parts['host'] . $parts['path'] . '?' . $parts['query'];
        
        expect($rebuilt)->toBe($url);
    });

});
