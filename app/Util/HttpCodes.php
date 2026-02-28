<?php

namespace App\Util;

/**
 * Helper pour les codes de statut HTTP
 * Fournit les descriptions et couleurs pour tous les codes HTTP standards et non-standards
 */
class HttpCodes
{
    /**
     * Tableau associatif complet des codes HTTP avec leurs descriptions
     */
    private static $codes = [
        // 0xx - Erreurs réseau/timeout
        0 => 'Timeout',
        
        // 1xx - Informational
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        
        // 2xx - Success
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        226 => 'IM Used',
        
        // 3xx - Redirection
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        311 => 'JavaScript Redirect',
        
        // 4xx - Client Error
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        419 => 'Page Expired',
        420 => 'Method Failure',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        440 => 'Login Time-out',
        444 => 'No Response',
        449 => 'Retry With',
        450 => 'Blocked by Windows Parental Controls',
        451 => 'Unavailable For Legal Reasons',
        
        // 5xx - Server Error
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        509 => 'Bandwidth Limit Exceeded',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
        520 => 'Web Server Returned an Unknown Error',
        
        // Codes non-standards (Cloudflare, nginx, etc.)
        494 => 'Request Header Too Large',
        495 => 'SSL Certificate Error',
        496 => 'SSL Certificate Required',
        497 => 'HTTP Request Sent to HTTPS Port',
        498 => 'Invalid Token',
        499 => 'Client Closed Request',
        598 => 'Network Read Timeout Error',
        599 => 'Network Connect Timeout Error',
    ];

    /**
     * Obtenir la description d'un code HTTP
     * 
     * @param int $code Code HTTP
     * @return string Description du code
     */
    public static function getLabel($code)
    {
        return self::$codes[$code] ?? 'Unknown';
    }

    /**
     * Obtenir la couleur associée à un code HTTP
     * 
     * @param int $code Code HTTP
     * @return string Couleur hexadécimale
     */
    public static function getColor($code)
    {
        if ($code == 0) {
            return '#e43926ff'; // Rouge pour timeout
        } elseif ($code >= 100 && $code < 200) {
            return '#6ab7ebff'; // Bleu pour 1xx (informational)
        } elseif ($code >= 200 && $code < 300) {
            return '#4bb975ff'; // Vert pour 2xx (success)
        } elseif ($code >= 300 && $code < 400) {
            return '#b3b01fff'; // Jaune pour 3xx (redirection)
        } elseif ($code >= 400 && $code < 500) {
            return '#ce6f30ff'; // Orange pour 4xx (client error)
        } elseif ($code >= 500 && $code < 600) {
            return '#e43926ff'; // Noir pour 5xx (server error)
        } else {
            return '#95a5a6'; // Gris par défaut
        }
    }

    /**
     * Obtenir la valeur d'affichage d'un code HTTP
     * Pour le code 311 (JS Redirect), retourne "JS Redirect" au lieu du code numérique
     * 
     * @param int $code Code HTTP
     * @return string Valeur à afficher
     */
    public static function getDisplayCode($code)
    {
        if ($code === 311) {
            return 'JS Redirect';
        }
        return (string)$code;
    }

    /**
     * Obtenir le code et sa description formatés
     * Pour le code 311, retourne "JS Redirect" au lieu de "311 - JavaScript Redirect"
     * 
     * @param int $code Code HTTP
     * @return string Code et description (ex: "200 - OK")
     */
    public static function getFullLabel($code)
    {
        if ($code === 311) {
            return 'JS Redirect';
        }
        return $code . ' - ' . self::getLabel($code);
    }

    /**
     * Convertir une couleur hex en rgba avec opacity
     * 
     * @param string $hex Couleur hexadécimale
     * @param float $opacity Opacité (0-1)
     * @return string Couleur rgba
     */
    public static function hexToRgba($hex, $opacity = 0.3)
    {
        $hex = ltrim($hex, '#');
        
        // Gérer les formats courts et longs
        if (strlen($hex) === 3) {
            $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
            $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
            $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        
        return "rgba($r, $g, $b, $opacity)";
    }

    /**
     * Obtenir la couleur de fond pour un badge (avec opacity)
     * 
     * @param int $code Code HTTP
     * @param float $opacity Opacité du fond (défaut: 0.3)
     * @return string Couleur rgba
     */
    public static function getBackgroundColor($code, $opacity = 0.3)
    {
        return self::hexToRgba(self::getColor($code), $opacity);
    }

    /**
     * Vérifier si un code est une erreur (4xx ou 5xx)
     * 
     * @param int $code Code HTTP
     * @return bool
     */
    public static function isError($code)
    {
        return $code >= 400 && $code < 600;
    }

    /**
     * Vérifier si un code est un succès (2xx)
     * 
     * @param int $code Code HTTP
     * @return bool
     */
    public static function isSuccess($code)
    {
        return $code >= 200 && $code < 300;
    }

    /**
     * Vérifier si un code est une redirection (3xx)
     * 
     * @param int $code Code HTTP
     * @return bool
     */
    public static function isRedirect($code)
    {
        return ($code >= 300 && $code < 400) || $code === 311;
    }

    /**
     * Obtenir tous les codes disponibles
     * 
     * @return array
     */
    public static function getAllCodes()
    {
        return self::$codes;
    }
}
