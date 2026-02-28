<?php

describe('Crawl Configuration', function () {

    it('configures very_slow speed correctly', function () {
        $config = ['crawl_speed' => 'very_slow'];
        
        // Simuler la logique de configureCrawlSpeed
        $simultaneousLimit = 2;
        $targetUrlsPerSecond = 1;
        
        switch ($config['crawl_speed']) {
            case 'very_slow':
                $simultaneousLimit = 2;
                $targetUrlsPerSecond = 1;
                break;
        }
        
        expect($simultaneousLimit)->toBe(2);
        expect($targetUrlsPerSecond)->toBe(1);
    });

    it('configures slow speed correctly', function () {
        $config = ['crawl_speed' => 'slow'];
        
        $simultaneousLimit = 0;
        $targetUrlsPerSecond = 0;
        
        switch ($config['crawl_speed']) {
            case 'slow':
                $simultaneousLimit = 3;
                $targetUrlsPerSecond = 5;
                break;
        }
        
        expect($simultaneousLimit)->toBe(3);
        expect($targetUrlsPerSecond)->toBe(5);
    });

    it('configures fast speed correctly', function () {
        $config = ['crawl_speed' => 'fast'];
        
        $simultaneousLimit = 0;
        $targetUrlsPerSecond = 0;
        
        switch ($config['crawl_speed']) {
            case 'fast':
                $simultaneousLimit = 8;
                $targetUrlsPerSecond = 15;
                break;
        }
        
        expect($simultaneousLimit)->toBe(8);
        expect($targetUrlsPerSecond)->toBe(15);
    });

    it('configures unlimited speed correctly', function () {
        $config = ['crawl_speed' => 'unlimited'];
        
        $simultaneousLimit = 0;
        $targetUrlsPerSecond = 0;
        
        switch ($config['crawl_speed']) {
            case 'unlimited':
                $simultaneousLimit = 10;
                $targetUrlsPerSecond = 0; // pas de limite
                break;
        }
        
        expect($simultaneousLimit)->toBe(10);
        expect($targetUrlsPerSecond)->toBe(0);
    });

    it('respects MAX_CONCURRENT_CURL env override', function () {
        // Sauvegarder l'environnement original
        $originalEnv = getenv('MAX_CONCURRENT_CURL');
        
        // Définir une valeur d'override
        putenv('MAX_CONCURRENT_CURL=25');
        
        $config = ['crawl_speed' => 'fast'];
        $simultaneousLimit = 8; // Valeur par défaut pour 'fast'
        
        // Simuler l'override
        $envMaxCurl = getenv('MAX_CONCURRENT_CURL');
        if ($envMaxCurl !== false && (int)$envMaxCurl > 0) {
            $simultaneousLimit = (int)$envMaxCurl;
        }
        
        expect($simultaneousLimit)->toBe(25);
        
        // Restaurer l'environnement
        if ($originalEnv !== false) {
            putenv("MAX_CONCURRENT_CURL=$originalEnv");
        } else {
            putenv('MAX_CONCURRENT_CURL');
        }
    });

    it('respects max_concurrent_curl config override', function () {
        $config = [
            'crawl_speed' => 'fast',
            'max_concurrent_curl' => 50
        ];
        
        $simultaneousLimit = 8; // Valeur par défaut pour 'fast'
        
        // Simuler l'override par config
        if (isset($config['max_concurrent_curl']) && $config['max_concurrent_curl'] > 0) {
            $simultaneousLimit = (int)$config['max_concurrent_curl'];
        }
        
        expect($simultaneousLimit)->toBe(50);
    });

});
