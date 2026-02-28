<?php

namespace App\Util;

/**
 * Classe utilitaire pour le parsing HTML/XML
 * Remplace mitseo/scraper avec des fonctions PHP natives
 */
class HtmlParser
{
    /**
     * Vérifie si un pattern regex matche une chaîne
     */
    public static function regexMatch(string $pattern, string $subject): bool
    {
        return preg_match($pattern, $subject) === 1;
    }

    /**
     * Extrait le premier groupe capturant d'une regex
     */
    public static function regexExtractFirst(string $pattern, string $subject): ?string
    {
        if (preg_match($pattern, $subject, $matches)) {
            return $matches[1] ?? $matches[0] ?? null;
        }
        return null;
    }

    /**
     * Extrait la première valeur d'une expression XPath
     */
    public static function xpathExtractFirst(string $xpath, string $html): ?string
    {
        if (empty($html)) {
            return null;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpathObj = new \DOMXPath($dom);
        $nodes = $xpathObj->query($xpath);

        if ($nodes && $nodes->length > 0) {
            $node = $nodes->item(0);
            if ($node instanceof \DOMAttr) {
                return $node->value;
            }
            return $node->textContent ?? $node->nodeValue;
        }

        return null;
    }

    /**
     * Extrait un arbre de données depuis une expression XPath avec attributs
     * 
     * @param string $xpath Expression XPath pour sélectionner les éléments
     * @param array $attributes Mapping des clés => expressions XPath relatives (ex: ["target" => "@href", "anchor" => "."])
     * @param string $html Le HTML à parser
     * @return array Liste d'arrays associatifs
     */
    public static function xpathExtractTree(string $xpath, array $attributes, string $html): array
    {
        if (empty($html)) {
            return [];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpathObj = new \DOMXPath($dom);
        $nodes = $xpathObj->query($xpath);

        $results = [];
        if ($nodes) {
            foreach ($nodes as $node) {
                $item = [];
                foreach ($attributes as $key => $attrXpath) {
                    if ($attrXpath === '.') {
                        $item[$key] = $node->textContent;
                    } elseif (str_starts_with($attrXpath, '@')) {
                        $attrName = substr($attrXpath, 1);
                        $item[$key] = $node->getAttribute($attrName);
                    } else {
                        $subNodes = $xpathObj->query($attrXpath, $node);
                        if ($subNodes && $subNodes->length > 0) {
                            $subNode = $subNodes->item(0);
                            if ($subNode instanceof \DOMAttr) {
                                $item[$key] = $subNode->value;
                            } else {
                                $item[$key] = $subNode->textContent;
                            }
                        } else {
                            $item[$key] = null;
                        }
                    }
                }
                $results[] = $item;
            }
        }

        return $results;
    }
}
