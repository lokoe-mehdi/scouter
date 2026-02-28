<?php
/**
 * Script de test pour vérifier le parsing du robots.txt
 */

require_once __DIR__ . '/../app/Analysis/RobotsTxt.php';

use App\Analysis\RobotsTxt;

// Test des règles problématiques
$testRules = [
    'Disallow: /blogs/*+*' => [
        'should_allow' => [
            'https://pranafoods.ca/blogs/breakfast',
            'https://pranafoods.ca/blogs/recipes',
            'https://pranafoods.ca/blogs/test-recipe'
        ],
        'should_block' => [
            'https://pranafoods.ca/blogs/test+recipe',
            'https://pranafoods.ca/blogs/breakfast+lunch',
            'https://pranafoods.ca/blogs/a+b+c'
        ]
    ],
    'Disallow: /collections/*+*' => [
        'should_allow' => [
            'https://pranafoods.ca/collections/all',
            'https://pranafoods.ca/collections/new-products'
        ],
        'should_block' => [
            'https://pranafoods.ca/collections/all+featured',
            'https://pranafoods.ca/collections/test+test'
        ]
    ]
];

echo "=== Test du parser robots.txt ===\n\n";

foreach ($testRules as $rule => $tests) {
    echo "Règle: $rule\n";
    echo str_repeat('-', 60) . "\n";
    
    echo "\n✓ URLs qui DEVRAIENT ÊTRE AUTORISÉES:\n";
    foreach ($tests['should_allow'] as $url) {
        $allowed = RobotsTxt::robots_allowed($url);
        $status = $allowed ? '✓ OK' : '✗ ERREUR - BLOQUÉ';
        echo "  $status : $url\n";
    }
    
    echo "\n✗ URLs qui DEVRAIENT ÊTRE BLOQUÉES:\n";
    foreach ($tests['should_block'] as $url) {
        $allowed = RobotsTxt::robots_allowed($url);
        $status = !$allowed ? '✓ OK' : '✗ ERREUR - AUTORISÉ';
        echo "  $status : $url\n";
    }
    
    echo "\n" . str_repeat('=', 60) . "\n\n";
}

echo "\nTest terminé!\n";
