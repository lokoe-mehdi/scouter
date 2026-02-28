<?php

use App\Analysis\Simhash;

describe('Simhash', function () {
    
    it('returns null for empty text', function () {
        expect(Simhash::compute(''))->toBeNull();
        expect(Simhash::compute('   '))->toBeNull();
    });

    it('computes a 64-bit hash for valid text', function () {
        $text = 'This is a sample text for testing the simhash algorithm';
        $hash = Simhash::compute($text);
        
        expect($hash)->toBeInt();
        expect($hash)->not->toBeNull();
    });

    it('returns same hash for identical texts', function () {
        $text = 'The quick brown fox jumps over the lazy dog';
        
        $hash1 = Simhash::compute($text);
        $hash2 = Simhash::compute($text);
        
        expect($hash1)->toBe($hash2);
    });

    it('returns similar hashes for similar texts', function () {
        $text1 = 'The quick brown fox jumps over the lazy dog';
        $text2 = 'The quick brown fox jumps over the lazy cat';
        
        $hash1 = Simhash::compute($text1);
        $hash2 = Simhash::compute($text2);
        
        $distance = Simhash::hammingDistance($hash1, $hash2);
        
        // Des textes similaires devraient avoir une distance faible (< 15)
        expect($distance)->toBeLessThan(15);
    });

    it('returns different hashes for different texts', function () {
        $text1 = 'The quick brown fox jumps over the lazy dog';
        $text2 = 'Lorem ipsum dolor sit amet consectetur adipiscing elit';
        
        $hash1 = Simhash::compute($text1);
        $hash2 = Simhash::compute($text2);
        
        expect($hash1)->not->toBe($hash2);
        
        $distance = Simhash::hammingDistance($hash1, $hash2);
        // Des textes très différents devraient avoir une grande distance
        expect($distance)->toBeGreaterThan(5);
    });

    it('calculates correct hamming distance', function () {
        // Deux hashes identiques = distance 0
        expect(Simhash::hammingDistance(0b1010, 0b1010))->toBe(0);
        
        // Un bit différent = distance 1
        expect(Simhash::hammingDistance(0b1010, 0b1011))->toBe(1);
        
        // Deux bits différents = distance 2
        expect(Simhash::hammingDistance(0b1010, 0b1001))->toBe(2);
        
        // Tous les bits différents sur 4 bits = distance 4
        expect(Simhash::hammingDistance(0b0000, 0b1111))->toBe(4);
    });

    it('detects similar content with areSimilar()', function () {
        $text1 = 'This is a long article about web crawling and SEO optimization techniques';
        $text2 = 'This is a long article about web crawling and SEO optimization methods';
        
        $hash1 = Simhash::compute($text1);
        $hash2 = Simhash::compute($text2);
        
        // Avec un seuil de 12 pour des textes très similaires
        expect(Simhash::areSimilar($hash1, $hash2, 12))->toBeTrue();
    });

    it('detects different content with areSimilar()', function () {
        $text1 = 'This is about cooking recipes and food preparation';
        $text2 = 'This is about software development and programming languages';
        
        $hash1 = Simhash::compute($text1);
        $hash2 = Simhash::compute($text2);
        
        // Des contenus très différents ne devraient pas être similaires
        expect(Simhash::areSimilar($hash1, $hash2, 3))->toBeFalse();
    });

    it('normalizes text correctly', function () {
        // Le même texte avec différentes casses et ponctuations devrait donner le même hash
        $text1 = 'Hello World This Is A Test';
        $text2 = 'hello world this is a test';
        $text3 = 'Hello, World! This is a test.';
        
        $hash1 = Simhash::compute($text1);
        $hash2 = Simhash::compute($text2);
        $hash3 = Simhash::compute($text3);
        
        // Les hashes devraient être identiques après normalisation
        expect($hash1)->toBe($hash2);
        expect($hash2)->toBe($hash3);
    });

});
