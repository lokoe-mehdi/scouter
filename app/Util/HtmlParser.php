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

    /**
     * Extrait les liens <a> avec leur XPath enrichi (tags + class/id des ancêtres)
     * et leur position sémantique (Navigation / Header / Footer / Aside / Content).
     *
     * Conçu pour ne JAMAIS faire planter le crawl :
     * - libxml errors silencieuses (HTML cassé fréquent sur le web)
     * - try/catch par lien : un lien foireux ne fait pas perdre les autres
     * - en cas d'échec individuel : xpath = null, position = "Content"
     *
     * @return array Liste d'arrays avec keys: target, anchor, rel, xpath, position
     */
    public static function extractLinksWithPosition(string $html): array
    {
        if (empty($html)) {
            return [];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpathObj = new \DOMXPath($dom);
        $nodes = $xpathObj->query('//a');

        $results = [];
        if (!$nodes) return $results;

        foreach ($nodes as $node) {
            // Valeurs par défaut au cas où le calcul échoue
            $xpath = null;
            $position = 'Content';

            try {
                $xpath = self::buildEnrichedXPath($node);
                $position = self::classifyLinkPosition($xpath);
            } catch (\Throwable $e) {
                error_log('[HtmlParser] XPath extraction failed for one link: ' . $e->getMessage());
                // xpath reste null, position reste "Content" — on enregistre le lien quand même
            }

            $results[] = [
                'target'   => $node->getAttribute('href'),
                'anchor'   => $node->textContent,
                'rel'      => $node->getAttribute('rel'),
                'xpath'    => $xpath,
                'position' => $position,
            ];
        }

        return $results;
    }

    /**
     * Construit un XPath absolu enrichi avec class/id des ancêtres.
     * Ex: /html[1]/body[1]/div[1][@id="header"]/nav[1][@class="main-menu"]/a[1]
     */
    private static function buildEnrichedXPath(\DOMNode $node): string
    {
        $segments = [];
        $current = $node;
        while ($current && $current->nodeType === XML_ELEMENT_NODE) {
            $tag = $current->nodeName;

            // Index parmi les siblings de même tag
            $index = 1;
            $sib = $current->previousSibling;
            while ($sib) {
                if ($sib->nodeType === XML_ELEMENT_NODE && $sib->nodeName === $tag) {
                    $index++;
                }
                $sib = $sib->previousSibling;
            }

            $extras = '';
            if ($current instanceof \DOMElement) {
                if ($current->hasAttribute('id')) {
                    // On évite de casser le xpath si l'id contient des "
                    $id = str_replace('"', '', $current->getAttribute('id'));
                    $extras .= '[@id="' . $id . '"]';
                }
                if ($current->hasAttribute('class')) {
                    // Limite à 200 chars pour éviter l'explosion sur les sites tailwind
                    $class = str_replace('"', '', $current->getAttribute('class'));
                    if (strlen($class) > 200) $class = substr($class, 0, 200);
                    $extras .= '[@class="' . $class . '"]';
                }
            }

            array_unshift($segments, $tag . '[' . $index . ']' . $extras);
            $current = $current->parentNode;
        }
        return '/' . implode('/', $segments);
    }

    /**
     * Classifie la position d'un lien selon son XPath enrichi.
     * Ordre IMPORTANT : Navigation gagne sur Header (cas <header><nav>).
     */
    private static function classifyLinkPosition(string $xpath): string
    {
        $lower = strtolower($xpath);
        if (str_contains($lower, 'nav') || str_contains($lower, 'menu')) return 'Navigation';
        if (str_contains($lower, 'header')) return 'Header';
        if (str_contains($lower, 'footer')) return 'Footer';
        if (str_contains($lower, 'aside')) return 'Aside';
        return 'Content';
    }
}
