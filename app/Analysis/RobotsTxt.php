<?php

namespace App\Analysis;

use App\Util\HtmlParser;

/**
 * Gestion et interprétation des fichiers robots.txt
 * 
 * Cette classe parse les fichiers robots.txt et détermine si une URL
 * est autorisée ou bloquée pour le crawl.
 * 
 * Fonctionnalités supportées :
 * - Règles `Allow` et `Disallow`
 * - Wildcards `*` dans les patterns
 * - Ancre de fin `$`
 * - User-agents multiples (Googlebot, *)
 * - Cache des robots.txt par domaine
 * 
 * @package    Scouter
 * @subpackage Crawler
 * @author     Mehdi Colin
 * @version    1.0.0
 * @since      1.0.0
 * 
 * @link https://developers.google.com/search/docs/crawling-indexing/robots/robots_txt
 */
class RobotsTxt
{
    static $robotsTxt;

    static function get($base)
    {
        if(!isset(self::$robotsTxt[$base]))
        {
            self::$robotsTxt[$base] = self::get_file($base."/robots.txt");
        }
        return self::$robotsTxt[$base];
    }

    static function get_file($url)
    {
        // Utiliser Googlebot comme User-Agent pour télécharger robots.txt
        $user_agent='Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $options = array(
            CURLOPT_CUSTOMREQUEST  =>"GET",        //set request type post or get
            CURLOPT_POST           =>false,        //set to GET
            CURLOPT_USERAGENT      => $user_agent, //set user agent
            CURLOPT_RETURNTRANSFER => true,     // return web page
            CURLOPT_HEADER         => false,    // don't return headers
            CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
            CURLOPT_TIMEOUT        => 120,      // timeout on response
            CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
            CURLOPT_SSL_VERIFYPEER => false
        );

        $ch      = curl_init($url);
        curl_setopt_array( $ch, $options );
        $content = curl_exec( $ch );
        curl_close( $ch );

        return $content;

    }

    static function robots_allowed($url, $useragent=false)
    {
      $path = HtmlParser::regexExtractFirst("#https?://[^/]+(.*)$#", $url);
      // GET robots.txt data
      $base_r = HtmlParser::regexExtractFirst("#(https?://[^/]+)#", $url);
      $robotsTxt = RobotsTxt::get($base_r);
      $robotsTxt = str_replace("\r","",$robotsTxt);
      $robotsTxt = explode("\n",$robotsTxt);
      //$robotsTxt;
      // List Agent
      $agents = ["*","Googlebot","googlebot"];
      if($useragent != false){ $agents[] = $useragent; }
      $active = false;
      $rules = [];
      foreach($robotsTxt as $line)
      {
        $line = trim($line);
        
        if(substr(trim($line),0,1)=='#') { $line = ''; } // Delete comments
        if(!empty($line))
        {
          if(preg_match("#^[uU]ser-[aA]gent:(.*)$#isU",$line,$matches))
          {
            if(in_array(trim($matches[1]),$agents)) { $active = true; }
            else { $active = false;}
          }
  
          if($active == true && preg_match("#^disallow:(.*)$#isU",$line,$matches))
          {
            $rule = trim($matches[1]);
            // Vérifier si la règle se termine par $ (ancre de fin robots.txt)
            $hasEndAnchor = substr($rule, -1) === '$';
            if ($hasEndAnchor) {
                $rule = substr($rule, 0, -1); // Retirer le $ temporairement
            }
            // Échapper TOUS les caractères spéciaux regex AVANT de traiter le wildcard *
            $rule = str_replace('\\','\\\\', $rule); // Échapper les backslashes
            $rule = str_replace('.','\.',$rule);
            $rule = str_replace('|','\|',$rule);
            $rule = str_replace('?','\?',$rule);
            $rule = str_replace('[','\[',$rule);
            $rule = str_replace(']','\]',$rule);
            $rule = str_replace('+','\+',$rule);  // FIX: échapper le +
            $rule = str_replace('(','\(',$rule);  // FIX: échapper (
            $rule = str_replace(')','\)',$rule);  // FIX: échapper )
            $rule = str_replace('{','\{',$rule);  // FIX: échapper {
            $rule = str_replace('}','\}',$rule);  // FIX: échapper }
            $rule = str_replace('^','\^',$rule);  // FIX: échapper ^
            $rule = str_replace('$','\$',$rule);  // FIX: échapper $ (sauf ancre de fin)
            // Maintenant traiter le wildcard * qui devient .*
            $rule = str_replace('*','.*',$rule);
            // Ajouter ^ au début
            $rule = '^'.$rule;
            // Si ancre de fin explicite, ajouter $ ; sinon matcher le reste de l'URL
            if ($hasEndAnchor) {
                $rule .= '$';
            } elseif(substr($rule,-1,1)=='/') {
                $rule .= '.*$';
            } else {
                $rule .= '.*$';
            }
  
            if(!empty(trim($matches[1]))) $rules[] = ["disallow",$rule];
          }
  
          if($active == true && preg_match("#^allow:(.*)$#isU",$line,$matches))
          {
            $rule = trim($matches[1]);
            // Vérifier si la règle se termine par $ (ancre de fin robots.txt)
            $hasEndAnchor = substr($rule, -1) === '$';
            if ($hasEndAnchor) {
                $rule = substr($rule, 0, -1); // Retirer le $ temporairement
            }
            // Échapper TOUS les caractères spéciaux regex AVANT de traiter le wildcard *
            $rule = str_replace('\\','\\\\', $rule); // Échapper les backslashes
            $rule = str_replace('.','\.',$rule);
            $rule = str_replace('|','\|',$rule);
            $rule = str_replace('?','\?',$rule);
            $rule = str_replace('[','\[',$rule);
            $rule = str_replace(']','\]',$rule);
            $rule = str_replace('+','\+',$rule);  // FIX: échapper le +
            $rule = str_replace('(','\(',$rule);  // FIX: échapper (
            $rule = str_replace(')','\)',$rule);  // FIX: échapper )
            $rule = str_replace('{','\{',$rule);  // FIX: échapper {
            $rule = str_replace('}','\}',$rule);  // FIX: échapper }
            $rule = str_replace('^','\^',$rule);  // FIX: échapper ^
            $rule = str_replace('$','\$',$rule);  // FIX: échapper $ (sauf ancre de fin)
            // Maintenant traiter le wildcard * qui devient .*
            $rule = str_replace('*','.*',$rule);
            // Ajouter ^ au début
            $rule = '^'.$rule;
            // Si ancre de fin explicite, ajouter $ ; sinon matcher le reste de l'URL
            if ($hasEndAnchor) {
                $rule .= '$';
            } elseif(substr($rule,-1,1)=='/') {
                $rule .= '.*$';
            } else {
                $rule .= '.*$';
            }
                    
            if(!empty(trim($matches[1]))) $rules[] = ["allow",$rule];
          }
  
        }
      }
 
    $allow=true;
      foreach($rules as $rule)
      {
        if(preg_match("#".$rule[1]."#",$path))
        {
          if($rule[0] == "disallow") { $allow = false; }
          if($rule[0] == "allow") { $allow = true; }
        }
      }
      return $allow;
  
    }
}