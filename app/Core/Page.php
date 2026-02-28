<?php

namespace App\Core;

use Xparse\ElementFinder\ElementFinder;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use App\Util\HtmlParser;
use App\Analysis\RobotsTxt;
use App\Analysis\Simhash;

/**
 * Analyse et extraction des données d'une page web
 * 
 * Cette classe parse le contenu HTML d'une page crawlée et extrait :
 * - **SEO** : Title, H1, meta description, canonical, robots
 * - **Liens** : Tous les liens internes/externes avec leurs ancres
 * - **Contenu** : Nombre de mots, structure des headings, Simhash
 * - **Technique** : Code HTTP, redirections, encodage
 * - **Schemas** : Types JSON-LD détectés
 * - **Custom** : Extracteurs XPath/Regex configurés par l'utilisateur
 * 
 * @package    Scouter
 * @subpackage Parser
 * @author     Mehdi Colin
 * @version    2.0.0
 * @since      1.0.0
 * 
 * @see PageCrawler Pour l'intégration avec le crawler
 * @see HtmlParser Pour les fonctions d'extraction
 */
class Page
{

    private $url;
    private $dom;
    private $config;
    private $extracts;
    private $links;
    private $base;
    private $domObject;
    private $headers;
    private $crawlConfig;
    private $pattern;

    public function __construct($url,$headers,$dom,$pattern,$crawlConfig)
    {
        // Ne pas encoder l'URL - conserver les caractères percent-encoded tels quels
        $this->url = $url;
        $this->base = HtmlParser::regexExtractFirst("/(.*\/?)/", $this->url);
        $this->headers = $headers;
        $this->pattern = $pattern;  
        $this->dom = $dom;    
        $this->crawlConfig = $crawlConfig;
        
        if($this->isHtml($this->dom) && stristr($this->headers->content_type,'html'))
        {
            $this->dom = $this->encode($dom);
            $this->domObject = new ElementFinder($dom);
            $base = $this->domObject->value('//base/@href')->first();
            if(!empty($base))
            {
             $this->base = $base;
            }
            
            $this->domAbs();
            $this->parse();
            $this->extract();
            $this->configuration();
        }

        
       
    }

    public function getPage()
    {
        preg_match("#https?:\/\/([^/\?]+)#i",$this->url,$dom);
        $dom = $dom[1];

        // Déterminer is_html et simhash
        $isHtmlContent = $this->detectIsHtml();
        $simhash = $isHtmlContent ? $this->computeSimhash() : null;
        
        if($this->isHtml($this->dom) && stristr($this->headers->content_type,'html') && $this->headers->http_code == 200)
        {
            // Analyser la hiérarchie des headings
            $headingsAnalysis = $this->analyzeHeadings();
            
            $page = (object) [
                "id" => $this->hash($this->url),
                "url" => $this->url,
                "domain" => $dom,
                "domain_id" => $this->hash($dom),
                "headers" => (object)[
                    "http_code" => $this->headers->http_code,
                    "redirect_to" => isset($this->headers->redirect_url)?$this->headers->redirect_url:0,
                    "redirect_hash" => isset($this->headers->redirect_url)?$this->hash($this->headers->redirect_url):0,
                    "response_time" => $this->headers->starttransfer_time ?? $this->headers->total_time,
                    "size" => $this->headers->size_download,
                    "content_type" => $this->headers->content_type
                ],
                "config" => $this->config,
                "domZip" => $this->zipDom($this->dom),
                "domHash" => sha1($this->dom),
                "extracts" => $this->extracts,
                "links" => $this->links,
                "is_html" => $isHtmlContent,
                "simhash" => $simhash,
                "h1_multiple" => $headingsAnalysis['h1_multiple'],
                "headings_missing" => $headingsAnalysis['headings_missing'],
                "schemas" => $this->extractSchemaTypes(),
                "word_count" => $this->calculateWordCount()
            ];
        }
        else
        {
            $page = (object) [
                "id" => $this->hash($this->url),
                "url" => $this->url,
                "domain" => $dom,
                "domain_id" => $this->hash($dom),
                "headers" => (object)[
                    "http_code" => $this->headers->http_code,
                    "redirect_to" => isset($this->headers->redirect_url)?$this->headers->redirect_url:0,
                    "redirect_hash" => isset($this->headers->redirect_url)?$this->hash($this->headers->redirect_url):0,
                    "response_time" => $this->headers->starttransfer_time ?? $this->headers->total_time,
                    "size" => $this->headers->size_download,
                    "content_type" => $this->headers->content_type
                ],
                "config" => [
                    "nofollow" => 0,
                    "noindex" => 0,
                    "canonical" => 1
                ],
                "domZip" => '',
                "domHash" => '',
                "extracts" => [
                    "title"=>"",
                    "meta_desc"=>"",
                    "h1"=>"",
                    "canonical"=>""
                ],
                "links" => [],
                "is_html" => $isHtmlContent,
                "simhash" => $simhash,
                "h1_multiple" => false,
                "headings_missing" => false,
                "schemas" => [],
                "word_count" => 0
            ];
        }
        return $page;
    }

    private function configuration()
    {
        $canonical = 1;
        if(!empty(trim($this->extracts['canonical'] ?? '')) && $this->extracts['canonical'] != $this->url)
        {
            $canonical = 0;
        }
            
        $robotsTag = $this->robotsTag();
        $xRobotsTag = (isset($this->headers->{'X-Robots-Tag'}))?$this->headers->{'X-Robots-Tag'}:0;

        if($xRobotsTag && stristr($xRobotsTag,"nofollow") != false) { $robotsTag["nofollow"] = 1; }
        if($xRobotsTag && stristr($xRobotsTag,"noindex") != false) { $robotsTag["noindex"] = 1; }
        

        $this->config = [
            "nofollow" => $robotsTag["nofollow"],
            "noindex" => $robotsTag["noindex"],
            "canonical" => $canonical,
        ];

        /* IF CONFIG */
    }

    public function robotsTag()
    {
            $robotsTag = $this->domObject->value('//meta[@name="robots"]/@content')->first();
            $output = [];
            if(!$robotsTag || stristr($robotsTag,"noindex") === FALSE) { $output["noindex"] = 0; }
            else { $output["noindex"] = 1; }

            if(!$robotsTag || stristr($robotsTag,"nofollow") === FALSE) { $output["nofollow"] = 0; }
            else { $output["nofollow"] = 1; }
            return $output;
    }

    private function isHtml($string)
    {
        if ( $string != strip_tags($string) )
        {
            return true; // Contains HTML
        }
        return false; // Does not contain HTML
    }

    private function encode($str,$to="UTF-8")
    {
        $str = str_replace("\r\n"," ",$str);
        $str = str_replace("\n"," ",$str);
        if($this->ishtml($str))
        {
            $str = str_replace("'","'",$str);
            $char_src = HtmlParser::xpathExtractFirst("//meta/@charset", $str);
            if(empty(trim($char_src ?? ''))){
                $char_src = HtmlParser::regexExtractFirst('/charset=([^"]+)"/', $str);
            }
            
            if(empty(trim($char_src ?? ''))){
                $charsets = mb_list_encodings();
                $char_src = mb_detect_encoding($str,$charsets);
            }
            
            // Nettoyer et valider le charset
            $char_src = $this->normalizeEncoding($char_src);
            
            try {
                $encodeString = mb_convert_encoding($str, $to, $char_src);
                return $encodeString;
            } catch (\ValueError $e) {
                // Fallback: essayer de détecter l'encodage ou forcer UTF-8
                $detected = mb_detect_encoding($str, mb_list_encodings(), true);
                if ($detected && $detected !== $to) {
                    try {
                        return mb_convert_encoding($str, $to, $detected);
                    } catch (\ValueError $e2) {
                        return $str;
                    }
                }
                return $str;
            }
        } else {
            return $str;
        }
    }
    
    /**
     * Normalise un nom d'encodage pour qu'il soit valide
     */
    private function normalizeEncoding($encoding)
    {
        if (empty($encoding)) {
            return 'UTF-8';
        }
        
        // Nettoyer le charset
        $encoding = trim($encoding);
        $encoding = strtoupper($encoding);
        
        // Corrections courantes (ex: utf=8 -> UTF-8, utf 8 -> UTF-8)
        $encoding = preg_replace('/[^A-Z0-9\-]/', '-', $encoding);
        $encoding = preg_replace('/-+/', '-', $encoding);
        $encoding = trim($encoding, '-');
        
        // Mappings des encodages mal formatés courants
        $mappings = [
            'UTF8' => 'UTF-8',
            'UTF-8' => 'UTF-8',
            'ISO88591' => 'ISO-8859-1',
            'ISO-8859-1' => 'ISO-8859-1',
            'ISO885915' => 'ISO-8859-15',
            'WINDOWS1252' => 'Windows-1252',
            'LATIN1' => 'ISO-8859-1',
        ];
        
        if (isset($mappings[$encoding])) {
            return $mappings[$encoding];
        }
        
        // Vérifier si l'encodage est supporté
        $validEncodings = mb_list_encodings();
        $validEncodingsUpper = array_map('strtoupper', $validEncodings);
        
        $index = array_search($encoding, $validEncodingsUpper);
        if ($index !== false) {
            return $validEncodings[$index];
        }
        
        // Fallback
        return 'UTF-8';
    }

    private function extract()
    {
        $dom = $this->dom;
        $id = $this->hash($this->url);

        $base = $this->domObject->value('//base/@href')->first();
        if(!empty($base))
        {
           $this->base = $base;
        }

        $this->extracts = (object)[
            "title" => '',
            "meta_desc" => '',
            "h1" => '',
            "canonical" => ''
        ];

        if($this->isHtml($dom))
        {
                $page = $this->domObject;

                $this->extracts = [
                    "title" => $this->domObject->value('//title')->first(),
                    "meta_desc" => $this->domObject->value('//meta[@name="description"]/@content')->first(),
                    "h1" => $this->domObject->value('//h1')->first(),
                    "canonical" => $this->domObject->value('//link[@rel="canonical"]/@href')->first()
                ];
                if(!empty(trim($this->extracts["canonical"] ?? ''))){
                    $this->extracts["canonical"] = $this->rel2abs($this->base,$this->extracts["canonical"]);
                }
                //additionals extracts xpath
                foreach($this->crawlConfig['xPathExtractors'] as $key=>$xpath)
                {
                    try {
                        $domDoc = new \DOMDocument();
                        @$domDoc->loadHTML($dom);
                        $domXPath = new \DOMXPath($domDoc);
                        
                        // Détecter et extraire les fonctions XPath 2.0
                        $postProcessing = null;
                        $cleanXpath = $xpath;
                        
                        // Gérer replace(xpath, pattern, replacement)
                        if (preg_match('/^replace\s*\(\s*(.+?)\s*,\s*[\'"](.+?)[\'"]\s*,\s*[\'"](.*)[\'"]\s*\)$/i', $xpath, $m)) {
                            $cleanXpath = $m[1];
                            $postProcessing = ['type' => 'replace', 'pattern' => $m[2], 'replacement' => $m[3]];
                        }
                        // Gérer lower-case(xpath)
                        elseif (preg_match('/^lower-case\s*\(\s*(.+?)\s*\)$/i', $xpath, $m)) {
                            $cleanXpath = $m[1];
                            $postProcessing = ['type' => 'lower-case'];
                        }
                        // Gérer upper-case(xpath)
                        elseif (preg_match('/^upper-case\s*\(\s*(.+?)\s*\)$/i', $xpath, $m)) {
                            $cleanXpath = $m[1];
                            $postProcessing = ['type' => 'upper-case'];
                        }
                        // Gérer matches(xpath, pattern) - retourne true/false
                        elseif (preg_match('/^matches\s*\(\s*(.+?)\s*,\s*[\'"](.+?)[\'"]\s*\)$/i', $xpath, $m)) {
                            $cleanXpath = $m[1];
                            $postProcessing = ['type' => 'matches', 'pattern' => $m[2]];
                        }
                        // Gérer ends-with(xpath, suffix)
                        elseif (preg_match('/^ends-with\s*\(\s*(.+?)\s*,\s*[\'"](.+?)[\'"]\s*\)$/i', $xpath, $m)) {
                            $cleanXpath = $m[1];
                            $postProcessing = ['type' => 'ends-with', 'suffix' => $m[2]];
                        }
                        // Gérer tokenize(xpath, pattern)[index]
                        elseif (preg_match('/^tokenize\s*\(\s*(.+?)\s*,\s*[\'"](.+?)[\'"]\s*\)\s*\[(\d+)\]$/i', $xpath, $m)) {
                            $cleanXpath = $m[1];
                            $postProcessing = ['type' => 'tokenize', 'pattern' => $m[2], 'index' => (int)$m[3]];
                        }
                        
                        // Essayer d'évaluer l'expression XPath
                        $result = @$domXPath->evaluate($cleanXpath);
                        
                        $extractedValue = null;
                        
                        // Si c'est un nombre (count, sum, etc.)
                        if (is_numeric($result)) {
                            $extractedValue = $result;
                        }
                        // Si c'est un booléen
                        elseif (is_bool($result)) {
                            $extractedValue = $result ? 'true' : 'false';
                        }
                        // Si c'est une NodeList
                        elseif ($result instanceof \DOMNodeList && $result->length > 0) {
                            $extractedValue = $result->item(0)->nodeValue;
                        }
                        // Si c'est une string
                        elseif (is_string($result)) {
                            $extractedValue = $result;
                        }
                        // Fallback
                        else {
                            $extractedValue = $this->domObject->value($cleanXpath)->first();
                        }
                        
                        // Appliquer le post-processing XPath 2.0
                        if ($postProcessing && $extractedValue !== null) {
                            switch ($postProcessing['type']) {
                                case 'replace':
                                    $extractedValue = preg_replace('/' . $postProcessing['pattern'] . '/u', $postProcessing['replacement'], $extractedValue);
                                    break;
                                case 'lower-case':
                                    $extractedValue = mb_strtolower($extractedValue, 'UTF-8');
                                    break;
                                case 'upper-case':
                                    $extractedValue = mb_strtoupper($extractedValue, 'UTF-8');
                                    break;
                                case 'matches':
                                    $extractedValue = preg_match('/' . $postProcessing['pattern'] . '/u', $extractedValue) ? 'true' : 'false';
                                    break;
                                case 'ends-with':
                                    $extractedValue = (substr($extractedValue, -strlen($postProcessing['suffix'])) === $postProcessing['suffix']) ? 'true' : 'false';
                                    break;
                                case 'tokenize':
                                    $parts = preg_split('/' . $postProcessing['pattern'] . '/u', $extractedValue);
                                    $idx = $postProcessing['index'] - 1; // XPath est 1-indexed
                                    $extractedValue = isset($parts[$idx]) ? $parts[$idx] : '';
                                    break;
                            }
                        }
                        
                        $this->extracts[$key] = $extractedValue;
                        
                    } catch (\Exception $e) {
                        // En cas d'erreur, fallback sur la méthode originale
                        $this->extracts[$key] = $this->domObject->value($xpath)->first();
                    }
                }
                //additionals extracts regex
                foreach($this->crawlConfig['regexExtractors'] as $key=>$rx)
                {
                    $rx = str_replace("#","\#",$rx);
                    preg_match("#".$rx."#is",$dom,$match);
                    if(isset($match[1])) { $rx_extract=$match[1]; }
                    else { $rx_extract = ''; }
                    $this->extracts[$key] = $rx_extract;
                }

                /*
                    Fix canonical SLASH
                */
                if(!empty($this->extracts['canonical']))
                {
                    if(HtmlParser::regexMatch("#^https?://[^/]+$#", $this->extracts['canonical']) == true)
                    {
                        $this->extracts['canonical'] = $this->extracts['canonical']."/";
                    }
                }
        }
    }


    private function zipDom($str)
    {
        return base64_encode(gzdeflate($this->dom));
    }
    
    /**
     * Calcule le nombre de mots du contenu principal avec Readability
     */
    private function calculateWordCount(): int
    {
        try {
            if (empty($this->dom)) {
                return 0;
            }
            
            // Configuration Readability
            $configuration = new Configuration();
            $configuration->setFixRelativeURLs(false);
            $configuration->setSubstituteEntities(false);
            
            // Parser avec Readability
            $readability = new Readability($configuration);
            $readability->parse($this->dom);
            
            // Récupérer le contenu texte
            $content = $readability->getContent();
            if (empty($content)) {
                return 0;
            }
            
            // Nettoyer le HTML pour ne garder que le texte
            $text = strip_tags($content);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // Nettoyer les espaces multiples et compter les mots
            $text = preg_replace('/\s+/', ' ', trim($text));
            
            if (empty($text)) {
                return 0;
            }
            
            // Compter les mots
            $wordCount = str_word_count($text, 0);
            
            return $wordCount > 0 ? $wordCount : 0;
            
        } catch (ParseException $e) {
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function domAbs() {

        $this->dom = preg_replace_callback(
            '/href=["\'](.*)["\']/isU',
            function ($matches) {
                return 'href="'.$this->rel2abs($this->base,$matches[1]).'"';
            },
            $this->dom
        );
    }

    private function hash($url)
    {
        return hash('crc32', $url, FALSE);
    }

    private function isExternal($url){
        $external = 1;
        foreach($this->pattern as $domain){
            $domain = str_replace(".","\.",$domain);
            $domain = str_replace("*","[^\.]*",$domain);
            if(HtmlParser::regexMatch("#^https?://".$domain."#", trim($url))){
                $external=0;
            }
        }
        return $external;
    }

    private function parse()
    {
        $time = microtime(true);
        // Utiliser le DOM UTF-8 directement pour préserver les caractères internationaux
        $links = HtmlParser::xpathExtractTree("//a", ["target"=>"@href","anchor"=>".","rel"=>"@rel"], $this->dom);
        $extracts = [];
        foreach($links as $link)
        {
            $target = explode("#",$link['target']);
            $target = $target[0];
            
            // S'assurer que l'URL est absolue
            // Si l'URL est relative, la convertir en absolue
            if (!empty($target) && !preg_match('#^https?://#i', $target)) {
                $target = $this->rel2abs($this->base, $target);
            }

            if($this->checkLink($target))
            {
                $external = $this->isExternal($target);

                /* ROBOTS.TXT VERIF */

                $blocked = 0;
                
                if($external == 0)
                {
                    if(RobotsTxt::robots_allowed($target)==false)
                    {
                        $blocked=1;
                    }
                }

        
                

                /* ROBOTS VERIF FIN*/

                /*
                    Fix canonical SLASH
                */
                if(!empty($target))
                {
                    if(HtmlParser::regexMatch("#^https?://[^/]+$#", $target) == true)
                    {
                        $target = $target."/";
                    }
                }

                $nofollow = (stristr($link["rel"] ?? "","nofollow") === false)?0:1;
                $dom = HtmlParser::regexExtractFirst("/https?:\/\/([^\/]+)/", $target);
                $extracts[] = (object)[
                    "target" => $target,
                    "target_id" => $this->hash($target),
                    "target_dom" => $dom,
                    "target_dom_hash" => $this->hash($dom),
                    "external" => $external,
                    "anchor" => $link["anchor"],
                    "nofollow" => $nofollow,
                    "blocked" => $blocked
                ];
            }
        }
        $this->links = $extracts;

        //echo round(microtime(true)-$time,5)."\r\n";
    }

    private function checkLink($url)
    {
        $match = true;
        $url = trim($url);
        if(empty($url))
        {
            $match = false;
        }
        $blacklist = [
            "mailto:",
            "javascript:",
            "tel:"
        ];
        foreach($blacklist as $rule)
        {
            if(stristr($url,$rule)!=false)
            {
                $match = false;
            }
        }

        return $match;
    }

    private function rel2abs($base0, $rel0) {
        // init
        //dump($base0);
        $rel0 = html_entity_decode($rel0); // fix pb encodage
        $base = parse_url($base0);
        $rel = parse_url($rel0);

        // CORRECTION
        
        if(!is_array($rel))
        {
            $rel = ["path" => trim($rel0)];
        }

        // CORRECTION

        if (array_key_exists("path", $rel)) { $relPath = $rel["path"];} else {$relPath = "";}
        if (array_key_exists("path", $base)) {$basePath = $base["path"];} else {$basePath = "";}
        // if rel has scheme, it has everything
        if (array_key_exists("scheme", $rel)) {return $rel0;}
        // else use base scheme
        if (array_key_exists("scheme", $base)) {$abs = $base["scheme"];} else {$abs = "";}
        if (strlen($abs) > 0) {$abs .= "://";}
        // if rel has host, it has everything, so blank the base path
        // else use base host and carry on
        if (array_key_exists("host", $rel)) {
          $abs .= $rel["host"];
          if (array_key_exists("port", $rel)) {
            $abs .= ":";
            $abs .= $rel["port"];
          }
          $basePath = "";
        } else if (array_key_exists("host", $base)) {
          $abs .= $base["host"];
          if (array_key_exists("port", $base)) {
            $abs .= ":";
            $abs .= $base["port"];
          }
        }
        // if rel starts with slash, that's it
        if (strlen($relPath) > 0 && $relPath[0] == "/") {
            
            
            $retour = $abs . $relPath;
            if(isset($rel["query"]))
            {
                $retour .= "?";
                $retour .= $rel["query"];
            }

            return $retour;
        }
        // split the base path parts
        $parts = array();
        $absParts = explode("/", $basePath);
        foreach ($absParts as $part) {
          array_push($parts, $part);
        }
        // remove the first empty part
        while (count($parts) >= 1 && strlen($parts[0]) == 0) {
          array_shift($parts);
        }
        
        // split the rel base parts
        $relParts = explode("/", $relPath);
        if (count($relParts) > 0 && strlen($relParts[0]) > 0) {
          array_pop($parts);
        }
        // iterate over rel parts and do the math
        $addSlash = false;
        foreach ($relParts as $part) {
          if ($part == "") {
          } else if ($part == ".") {
            $addSlash = true;
          } else if ($part == "..") {
            array_pop($parts);
            $addSlash = true;
          } else {
            array_push($parts, $part);
            $addSlash = false;
          }
        }
        // combine the result
        foreach ($parts as $part) {
          $abs .= "/";
          $abs .= $part;
        }
        if ($addSlash) {
          $abs .= "/";
        }
        if (array_key_exists("query", $rel)) {
          $abs .= "?";
          $abs .= $rel["query"];
        }
        
        if (array_key_exists("fragment", $rel)) {
          $abs .= "#";
          $abs .= $rel["fragment"];
        }
        
        return $abs;
    }


    

    /**
     * Détecte si le contenu est du vrai HTML (pas PDF, image, média, etc.)
     * Utilise plusieurs heuristiques pour être robuste aux content-types incorrects.
     */
    private function detectIsHtml(): bool
    {
        $contentType = $this->headers->content_type ?? '';
        $url = $this->url;
        $content = $this->dom ?? '';
        
        // 1. Vérifier les extensions d'URL non-HTML connues
        $nonHtmlExtensions = [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif',
            'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a',
            'mp4', 'avi', 'mov', 'wmv', 'mkv', 'webm', 'flv',
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
            'exe', 'msi', 'dmg', 'apk', 'deb', 'rpm',
            'css', 'js', 'json', 'xml', 'rss', 'atom',
            'ttf', 'woff', 'woff2', 'eot', 'otf'
        ];
        
        // Extraire l'extension de l'URL (avant query string)
        $urlPath = parse_url($url, PHP_URL_PATH) ?? '';
        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        if (in_array($extension, $nonHtmlExtensions)) {
            return false;
        }
        
        // 2. Vérifier le Content-Type
        $contentTypeLower = strtolower($contentType);
        
        // Types clairement non-HTML
        $nonHtmlTypes = [
            'application/pdf', 'application/zip', 'application/octet-stream',
            'application/javascript', 'application/json', 'application/xml',
            'image/', 'audio/', 'video/', 'font/',
            'text/css', 'text/javascript', 'text/plain', 'text/xml'
        ];
        
        foreach ($nonHtmlTypes as $type) {
            if (strpos($contentTypeLower, $type) !== false) {
                return false;
            }
        }
        
        // Content-Type HTML explicite
        if (strpos($contentTypeLower, 'text/html') !== false || 
            strpos($contentTypeLower, 'application/xhtml') !== false) {
            return true;
        }
        
        // 3. Vérifier les magic bytes / signatures de fichiers binaires
        if (!empty($content)) {
            $firstBytes = substr($content, 0, 16);
            
            // Signatures binaires connues
            $binarySignatures = [
                "\x25\x50\x44\x46",           // PDF (%PDF)
                "\xFF\xD8\xFF",                // JPEG
                "\x89\x50\x4E\x47",            // PNG
                "\x47\x49\x46\x38",            // GIF (GIF8)
                "\x50\x4B\x03\x04",            // ZIP/DOCX/XLSX/etc
                "\x52\x61\x72\x21",            // RAR
                "\x1F\x8B",                    // GZIP
                "\x42\x4D",                    // BMP
                "\x00\x00\x00",                // Possible video/binary
                "\x49\x44\x33",                // MP3 (ID3)
                "\xFF\xFB",                    // MP3
                "\x4F\x67\x67\x53",            // OGG
            ];
            
            foreach ($binarySignatures as $sig) {
                if (strpos($firstBytes, $sig) === 0) {
                    return false;
                }
            }
            
            // 4. Vérifier la présence de balises HTML basiques
            $hasHtmlTags = preg_match('/<(!DOCTYPE|html|head|body|div|p|a|span|script|link|meta)/i', $content);
            if ($hasHtmlTags) {
                return true;
            }
            
            // 5. Heuristique finale: ratio de caractères imprimables
            // Les fichiers binaires ont beaucoup de caractères non-imprimables
            $sampleSize = min(1000, strlen($content));
            $sample = substr($content, 0, $sampleSize);
            $printableCount = preg_match_all('/[\x20-\x7E\x0A\x0D\x09]/', $sample);
            $printableRatio = $sampleSize > 0 ? $printableCount / $sampleSize : 0;
            
            // Si moins de 80% de caractères imprimables, probablement binaire
            if ($printableRatio < 0.8) {
                return false;
            }
        }
        
        // Par défaut, considérer comme HTML si pas de contenu ou indeterminé
        return !empty($content);
    }
    
    /**
     * Calcule le simhash du contenu textuel principal de la page.
     * Extrait le contenu de <main>, <article> ou <body> pour éviter le boilerplate.
     */
    private function computeSimhash(): ?int
    {
        if (empty($this->dom)) {
            return null;
        }
        
        // Extraire le contenu principal: <main> > <article> > <body>
        $content = null;
        
        // Essayer <main> d'abord
        if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $this->dom, $m)) {
            $content = $m[1];
        }
        // Sinon <article>
        elseif (preg_match('/<article[^>]*>(.*?)<\/article>/is', $this->dom, $m)) {
            $content = $m[1];
        }
        // Sinon <body>
        elseif (preg_match('/<body[^>]*>(.*?)<\/body>/is', $this->dom, $m)) {
            $content = $m[1];
        }
        // Fallback: tout le DOM
        else {
            $content = $this->dom;
        }
        
        // Supprimer nav, header, footer, aside du contenu
        // Protection: preg_replace peut retourner null sur gros HTML
        $cleaned = preg_replace('/<(nav|header|footer|aside)[^>]*>.*?<\/\1>/is', '', $content);
        if ($cleaned !== null) {
            $content = $cleaned;
        }
        
        // Protection contre content null ou vide
        if (empty($content)) {
            return null;
        }
        
        return Simhash::compute($content);
    }
    
    /**
     * Extrait le texte visible d'une page HTML (sans scripts, styles, nav, footer).
     */
    private function extractVisibleText(string $html): string
    {
        // Supprimer les balises qui ne contiennent pas de contenu visible
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        
        // Supprimer toutes les balises HTML
        $text = strip_tags($html);
        
        // Décoder les entités HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $text;
    }
    
    /**
     * Analyse la hiérarchie des headings (h1-h6) pour détecter les problèmes
     * 
     * @return array ['h1_multiple' => bool, 'headings_missing' => bool]
     */
    private function analyzeHeadings(): array
    {
        $result = [
            'h1_multiple' => false,
            'headings_missing' => false
        ];
        
        if (!$this->domObject) {
            return $result;
        }
        
        try {
            $domDoc = new \DOMDocument();
            @$domDoc->loadHTML($this->dom);
            $domXPath = new \DOMXPath($domDoc);
            
            // Récupérer tous les headings dans l'ordre du document
            $headings = $domXPath->query('//h1|//h2|//h3|//h4|//h5|//h6');
            
            if ($headings->length === 0) {
                // Pas de headings = pas de problème
                return $result;
            }
            
            // Compter les H1
            $h1Count = 0;
            $headingLevels = [];
            
            foreach ($headings as $heading) {
                $tagName = strtolower($heading->nodeName);
                $level = (int)substr($tagName, 1); // h1 -> 1, h2 -> 2, etc.
                
                if ($level === 1) {
                    $h1Count++;
                }
                
                $headingLevels[] = $level;
            }
            
            // Vérifier H1 multiple
            if ($h1Count > 1) {
                $result['h1_multiple'] = true;
            }
            
            // Vérifier les niveaux manquants (séquençage)
            // Règles:
            // 1. Le premier heading doit être h1 (sinon mauvaise structure)
            // 2. On ne doit pas sauter de niveau (ex: h2 -> h4 sans h3)
            $previousLevel = 0;
            
            foreach ($headingLevels as $index => $level) {
                // Premier heading : doit être h1
                if ($index === 0 && $level > 1) {
                    $result['headings_missing'] = true;
                    break;
                }
                
                // Headings suivants : on ne doit pas sauter de niveau
                // Ex: h2 (level 2) suivi de h4 (level 4) = gap de 2
                if ($previousLevel > 0 && $level > $previousLevel + 1) {
                    $result['headings_missing'] = true;
                    break;
                }
                $previousLevel = $level;
            }
            
        } catch (\Exception $e) {
            // En cas d'erreur, on retourne false pour les deux
        }
        
        return $result;
    }
    
    /**
     * Extrait les @type des données structurées (JSON-LD, Microdata, RDFa)
     * 
     * @return array Liste unique des types de schemas trouvés
     */
    private function extractSchemaTypes(): array
    {
        $types = [];
        
        if (empty($this->dom)) {
            return $types;
        }
        
        // 1. Extraire les JSON-LD
        $this->extractJsonLdTypes($types);
        
        // 2. Extraire les Microdata (itemscope + itemtype sans itemprop)
        $this->extractMicrodataTypes($types);
        
        // 3. Extraire les RDFa (typeof sans property)
        $this->extractRdfaTypes($types);
        
        // Retourner les types uniques
        return array_values(array_unique($types));
    }
    
    /**
     * Extrait les types depuis les balises JSON-LD
     */
    private function extractJsonLdTypes(array &$types): void
    {
        // Extraire toutes les balises <script type="application/ld+json">
        preg_match_all(
            '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            $this->dom,
            $matches
        );
        
        // Parser chaque bloc JSON
        foreach ($matches[1] as $jsonContent) {
            $jsonContent = trim($jsonContent);
            if (empty($jsonContent)) continue;
            
            $data = json_decode($jsonContent, true);
            
            // Si le JSON est invalide, on passe
            if (json_last_error() !== JSON_ERROR_NONE) continue;
            
            // Extraire uniquement les @type de premier niveau
            $this->extractTopLevelTypes($data, $types);
        }
    }
    
    /**
     * Extrait les types depuis les Microdata HTML (itemscope + itemtype)
     * 
     * Règle : On ne garde que les éléments racines (sans attribut itemprop)
     * pour éviter de compter les sous-types imbriqués.
     */
    private function extractMicrodataTypes(array &$types): void
    {
        if (!$this->domObject) {
            return;
        }
        
        try {
            $domDoc = new \DOMDocument();
            @$domDoc->loadHTML($this->dom);
            $domXPath = new \DOMXPath($domDoc);
            
            // Chercher les éléments avec itemscope ET itemtype MAIS SANS itemprop
            // XPath: //*[@itemscope][@itemtype][not(@itemprop)]
            $elements = $domXPath->query('//*[@itemscope][@itemtype][not(@itemprop)]');
            
            foreach ($elements as $element) {
                $itemtype = $element->getAttribute('itemtype');
                if (!empty($itemtype)) {
                    // Nettoyer l'URL pour garder juste le type
                    // Ex: "https://schema.org/Product" -> "Product"
                    $type = $this->cleanSchemaType($itemtype);
                    if (!empty($type)) {
                        $types[] = $type;
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs de parsing
        }
    }
    
    /**
     * Extrait les types depuis les RDFa (attribut typeof)
     * 
     * Règle : On ne garde que les éléments racines (sans attribut property)
     * pour éviter de compter les sous-types imbriqués.
     */
    private function extractRdfaTypes(array &$types): void
    {
        if (!$this->domObject) {
            return;
        }
        
        try {
            $domDoc = new \DOMDocument();
            @$domDoc->loadHTML($this->dom);
            $domXPath = new \DOMXPath($domDoc);
            
            // Chercher les éléments avec typeof MAIS SANS property
            // XPath: //*[@typeof][not(@property)]
            $elements = $domXPath->query('//*[@typeof][not(@property)]');
            
            foreach ($elements as $element) {
                $typeof = $element->getAttribute('typeof');
                if (!empty($typeof)) {
                    // typeof peut contenir plusieurs types séparés par des espaces
                    $typeList = preg_split('/\s+/', trim($typeof));
                    foreach ($typeList as $rawType) {
                        $type = $this->cleanSchemaType($rawType);
                        if (!empty($type)) {
                            $types[] = $type;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs de parsing
        }
    }
    
    /**
     * Nettoie un type schema.org pour ne garder que le nom du type
     * 
     * Ex: "https://schema.org/Product" -> "Product"
     * Ex: "schema:Product" -> "Product"
     * Ex: "Product" -> "Product"
     */
    private function cleanSchemaType(string $rawType): string
    {
        $rawType = trim($rawType);
        
        // Format URL: https://schema.org/Product ou http://schema.org/Product
        if (preg_match('#^https?://schema\.org/(.+)$#i', $rawType, $matches)) {
            return $matches[1];
        }
        
        // Format préfixé: schema:Product
        if (preg_match('/^schema:(.+)$/i', $rawType, $matches)) {
            return $matches[1];
        }
        
        // Format direct (déjà un nom de type)
        // Vérifier que c'est un nom valide (alphanumérique)
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $rawType)) {
            return $rawType;
        }
        
        return '';
    }
    
    /**
     * Extrait uniquement les @type de premier niveau (pas les sous-éléments imbriqués)
     * 
     * Formats supportés:
     * - { "@graph": [...] } : extrait le @type de chaque élément du graphe
     * - { "@type": "..." } : extrait directement le type
     * - [ { "@type": "..." }, ... ] : tableau d'objets à la racine
     */
    private function extractTopLevelTypes($data, array &$types): void
    {
        if (!is_array($data)) {
            return;
        }
        
        // Format @graph : extraire les types de chaque élément du graphe
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                $this->extractTypeFromObject($item, $types);
            }
            return;
        }
        
        // Objet simple avec @type à la racine
        if (isset($data['@type'])) {
            $this->extractTypeFromObject($data, $types);
            return;
        }
        
        // Tableau d'objets à la racine (sans @graph)
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $item) {
                $this->extractTypeFromObject($item, $types);
            }
        }
    }
    
    /**
     * Extrait le @type d'un objet JSON-LD (premier niveau uniquement)
     */
    private function extractTypeFromObject($item, array &$types): void
    {
        if (!is_array($item) || !isset($item['@type'])) {
            return;
        }
        
        // @type peut être une string ou un array de strings
        if (is_array($item['@type'])) {
            foreach ($item['@type'] as $type) {
                if (is_string($type)) {
                    $types[] = $type;
                }
            }
        } elseif (is_string($item['@type'])) {
            $types[] = $item['@type'];
        }
    }
}