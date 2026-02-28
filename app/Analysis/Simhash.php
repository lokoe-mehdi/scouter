<?php

namespace App\Analysis;

/**
 * Détection de contenu dupliqué via Simhash
 * 
 * Implémentation de l'algorithme Simhash pour détecter le contenu
 * dupliqué ou quasi-dupliqué (near-duplicate) entre les pages.
 * 
 * Le simhash est une empreinte 64-bit où des documents similaires
 * ont des hashes similaires. La distance de Hamming entre deux
 * simhashes indique le degré de similarité.
 * 
 * Seuils de similarité :
 * - Distance 0 : Contenu identique
 * - Distance 1-3 : Near-duplicate (très similaire)
 * - Distance 4-10 : Contenu lié (similaire)
 * - Distance >10 : Contenu différent
 * 
 * @package    Scouter
 * @subpackage Analytics
 * @author     Mehdi Colin
 * @version    1.0.0
 * @since      1.0.0
 * 
 * @link https://en.wikipedia.org/wiki/SimHash Algorithme SimHash
 */
class Simhash
{
    /**
     * Calcule le simhash d'un texte.
     * 
     * @param string $text Le texte à hasher
     * @return int|null Le simhash 64-bit, ou null si le texte est vide
     */
    public static function compute(string $text): ?int
    {
        // Nettoyer et normaliser le texte
        $text = self::normalize($text);
        
        if (empty($text)) {
            return null;
        }
        
        // Tokeniser en shingles (n-grams de mots)
        $tokens = self::tokenize($text);
        
        if (empty($tokens)) {
            return null;
        }
        
        // Calculer le simhash
        $v = array_fill(0, 64, 0);
        
        foreach ($tokens as $token) {
            // Hash 64-bit du token
            $hash = self::hash64($token);
            
            // Mettre à jour les compteurs pour chaque bit
            for ($i = 0; $i < 64; $i++) {
                if (($hash >> $i) & 1) {
                    $v[$i]++;
                } else {
                    $v[$i]--;
                }
            }
        }
        
        // Construire le simhash final
        $simhash = 0;
        for ($i = 0; $i < 64; $i++) {
            if ($v[$i] > 0) {
                $simhash |= (1 << $i);
            }
        }
        
        return $simhash;
    }
    
    /**
     * Calcule la distance de Hamming entre deux simhashes.
     * 
     * @param int $hash1 Premier simhash
     * @param int $hash2 Second simhash
     * @return int Le nombre de bits différents (0-64)
     */
    public static function hammingDistance(int $hash1, int $hash2): int
    {
        $xor = $hash1 ^ $hash2;
        $distance = 0;
        
        // Compter les bits à 1 (méthode de Kernighan)
        while ($xor) {
            $distance++;
            $xor &= $xor - 1;
        }
        
        return $distance;
    }
    
    /**
     * Vérifie si deux simhashes représentent des contenus similaires.
     * 
     * @param int $hash1 Premier simhash
     * @param int $hash2 Second simhash
     * @param int $threshold Seuil de distance (défaut: 3, environ 95% de similarité)
     * @return bool True si les contenus sont considérés comme similaires
     */
    public static function areSimilar(int $hash1, int $hash2, int $threshold = 3): bool
    {
        return self::hammingDistance($hash1, $hash2) <= $threshold;
    }
    
    /**
     * Normalise le texte pour le simhash.
     */
    private static function normalize(string $text): string
    {
        // Supprimer les balises HTML
        $text = strip_tags($text);
        
        // Décoder les entités HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Convertir en minuscules
        $text = mb_strtolower($text, 'UTF-8');
        
        // Supprimer la ponctuation - protection contre preg_replace null
        $result = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        if ($result !== null) {
            $text = $result;
        }
        
        // Normaliser les espaces - str_replace plus safe que preg_replace
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        while (strpos($text, '  ') !== false) {
            $text = str_replace('  ', ' ', $text);
        }
        
        return trim($text);
    }
    
    /**
     * Tokenise le texte en shingles (groupes de mots consécutifs).
     * 
     * @param string $text Texte normalisé
     * @param int $shingleSize Taille des shingles (défaut: 3 mots)
     * @return array Liste des shingles
     */
    private static function tokenize(string $text, int $shingleSize = 3): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        // Filtrer les mots trop courts (stop words implicites)
        $words = array_filter($words, fn($w) => mb_strlen($w) > 2);
        $words = array_values($words);
        
        if (count($words) < $shingleSize) {
            // Si pas assez de mots, retourner les mots individuels
            return $words;
        }
        
        // Créer les shingles
        $shingles = [];
        for ($i = 0; $i <= count($words) - $shingleSize; $i++) {
            $shingles[] = implode(' ', array_slice($words, $i, $shingleSize));
        }
        
        return $shingles;
    }
    
    /**
     * Génère un hash 64-bit pour un token.
     * PHP n'a pas de hash 64-bit natif, on combine deux hashes 32-bit.
     */
    private static function hash64(string $token): int
    {
        // Utiliser deux hashes différents pour créer un 64-bit
        $hash1 = crc32($token);
        $hash2 = crc32(strrev($token) . $token);
        
        // Combiner en 64-bit (attention aux systèmes 32-bit)
        if (PHP_INT_SIZE >= 8) {
            return ($hash1 << 32) | ($hash2 & 0xFFFFFFFF);
        } else {
            // Fallback pour systèmes 32-bit (moins précis)
            return $hash1 ^ $hash2;
        }
    }
}
