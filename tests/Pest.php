<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Vous pouvez définir une classe TestCase de base ici si nécessaire.
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
| Vous pouvez ajouter des expectations personnalisées ici.
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
| Fonctions helpers globales pour les tests.
*/

function sampleHtml(): string {
    return '<!DOCTYPE html><html><head><title>Test Page</title><meta name="description" content="Test description"></head><body><h1>Hello World</h1><a href="/page2">Link</a></body></html>';
}

function sampleUrl(): string {
    return 'https://example.com/page';
}
