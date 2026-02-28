<?php

use App\Util\JsRenderer;

describe('JsRenderer', function () {

    it('constructs with default URL', function () {
        // Sauvegarder et supprimer la variable d'environnement si elle existe
        $originalEnv = getenv('RENDERER_URL');
        putenv('RENDERER_URL');
        
        $renderer = new JsRenderer();
        
        // Restaurer l'environnement
        if ($originalEnv !== false) {
            putenv("RENDERER_URL=$originalEnv");
        }
        
        // Le renderer devrait être créé sans erreur
        expect($renderer)->toBeInstanceOf(JsRenderer::class);
    });

    it('constructs with custom URL from env', function () {
        // Définir une URL personnalisée
        $customUrl = 'http://custom-renderer:4000';
        putenv("RENDERER_URL=$customUrl");
        
        $renderer = new JsRenderer();
        
        // Nettoyer
        putenv('RENDERER_URL');
        
        expect($renderer)->toBeInstanceOf(JsRenderer::class);
    });

    it('constructs with explicit URL parameter', function () {
        $customUrl = 'http://explicit-renderer:5000';
        
        $renderer = new JsRenderer($customUrl);
        
        expect($renderer)->toBeInstanceOf(JsRenderer::class);
    });

    it('sets timeout correctly', function () {
        $renderer = new JsRenderer();
        
        // La méthode setTimeout devrait retourner $this pour le chaînage
        $result = $renderer->setTimeout(120);
        
        expect($result)->toBeInstanceOf(JsRenderer::class);
        expect($result)->toBe($renderer);
    });

    it('constructs with custom timeout', function () {
        $renderer = new JsRenderer(null, 30);
        
        expect($renderer)->toBeInstanceOf(JsRenderer::class);
    });

});
