<?php

use App\Analysis\RobotsTxt;

describe('RobotsTxt', function () {

    beforeEach(function () {
        // Reset le cache statique entre chaque test
        $reflection = new ReflectionClass(RobotsTxt::class);
        $property = $reflection->getProperty('robotsTxt');
        $property->setAccessible(true);
        $property->setValue(null, []);
    });

    it('allows all URLs when robots.txt is empty', function () {
        // Simuler un robots.txt vide en mockant la méthode statique
        // Pour ce test, on vérifie juste que la logique de parsing fonctionne
        $result = RobotsTxt::robots_allowed('https://example.com/any-page');
        
        // Par défaut, si pas de règle, tout est autorisé
        expect($result)->toBeTrue();
    });

    it('blocks URLs matching Disallow rules', function () {
        // Test de la logique de parsing avec un robots.txt simulé
        // On injecte directement dans le cache statique
        $robotsTxt = "User-agent: *\nDisallow: /admin/\nDisallow: /private/";
        
        $reflection = new ReflectionClass(RobotsTxt::class);
        $property = $reflection->getProperty('robotsTxt');
        $property->setAccessible(true);
        $property->setValue(null, ['https://test.local' => $robotsTxt]);
        
        // /admin/ devrait être bloqué
        $result = RobotsTxt::robots_allowed('https://test.local/admin/page');
        expect($result)->toBeFalse();
        
        // /private/ devrait être bloqué
        $result = RobotsTxt::robots_allowed('https://test.local/private/data');
        expect($result)->toBeFalse();
        
        // /public/ devrait être autorisé
        $result = RobotsTxt::robots_allowed('https://test.local/public/page');
        expect($result)->toBeTrue();
    });

    it('allows URLs matching Allow rules', function () {
        $robotsTxt = "User-agent: *\nDisallow: /admin/\nAllow: /admin/public/";
        
        $reflection = new ReflectionClass(RobotsTxt::class);
        $property = $reflection->getProperty('robotsTxt');
        $property->setAccessible(true);
        $property->setValue(null, ['https://test.local' => $robotsTxt]);
        
        // /admin/public/ devrait être autorisé malgré le Disallow /admin/
        $result = RobotsTxt::robots_allowed('https://test.local/admin/public/page');
        expect($result)->toBeTrue();
    });

    it('handles wildcard * in rules', function () {
        $robotsTxt = "User-agent: *\nDisallow: /*.pdf$";
        
        $reflection = new ReflectionClass(RobotsTxt::class);
        $property = $reflection->getProperty('robotsTxt');
        $property->setAccessible(true);
        $property->setValue(null, ['https://test.local' => $robotsTxt]);
        
        // Les fichiers PDF devraient être bloqués
        $result = RobotsTxt::robots_allowed('https://test.local/document.pdf');
        expect($result)->toBeFalse();
        
        // Les fichiers HTML devraient être autorisés
        $result = RobotsTxt::robots_allowed('https://test.local/page.html');
        expect($result)->toBeTrue();
    });

    it('handles $ end anchor in rules', function () {
        $robotsTxt = "User-agent: *\nDisallow: /page$";
        
        $reflection = new ReflectionClass(RobotsTxt::class);
        $property = $reflection->getProperty('robotsTxt');
        $property->setAccessible(true);
        $property->setValue(null, ['https://test.local' => $robotsTxt]);
        
        // /page exactement devrait être bloqué
        $result = RobotsTxt::robots_allowed('https://test.local/page');
        expect($result)->toBeFalse();
        
        // /page/subpage devrait être autorisé (ne finit pas par /page)
        $result = RobotsTxt::robots_allowed('https://test.local/page/subpage');
        expect($result)->toBeTrue();
    });

    it('respects User-Agent specificity', function () {
        $robotsTxt = "User-agent: Googlebot\nDisallow: /google-only/\n\nUser-agent: *\nDisallow: /all-bots/";
        
        $reflection = new ReflectionClass(RobotsTxt::class);
        $property = $reflection->getProperty('robotsTxt');
        $property->setAccessible(true);
        $property->setValue(null, ['https://test.local' => $robotsTxt]);
        
        // /all-bots/ devrait être bloqué pour User-agent: *
        $result = RobotsTxt::robots_allowed('https://test.local/all-bots/page');
        expect($result)->toBeFalse();
        
        // /google-only/ devrait être bloqué pour Googlebot
        $result = RobotsTxt::robots_allowed('https://test.local/google-only/page', 'Googlebot');
        expect($result)->toBeFalse();
    });

    it('ignores comments in robots.txt', function () {
        $robotsTxt = "# This is a comment\nUser-agent: *\n# Another comment\nDisallow: /blocked/";
        
        $reflection = new ReflectionClass(RobotsTxt::class);
        $property = $reflection->getProperty('robotsTxt');
        $property->setAccessible(true);
        $property->setValue(null, ['https://test.local' => $robotsTxt]);
        
        // /blocked/ devrait être bloqué
        $result = RobotsTxt::robots_allowed('https://test.local/blocked/page');
        expect($result)->toBeFalse();
        
        // Les commentaires ne devraient pas affecter le parsing
        $result = RobotsTxt::robots_allowed('https://test.local/allowed/page');
        expect($result)->toBeTrue();
    });

    it('handles malformed robots.txt gracefully', function () {
        // robots.txt mal formé
        $robotsTxt = "This is not a valid robots.txt\nRandom text\n\n\n";
        
        $reflection = new ReflectionClass(RobotsTxt::class);
        $property = $reflection->getProperty('robotsTxt');
        $property->setAccessible(true);
        $property->setValue(null, ['https://test.local' => $robotsTxt]);
        
        // Devrait autoriser par défaut si pas de règles valides
        $result = RobotsTxt::robots_allowed('https://test.local/any-page');
        expect($result)->toBeTrue();
    });

});
