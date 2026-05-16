<?php

namespace App\Util;

/**
 * Helper anti-SSRF (Server-Side Request Forgery).
 *
 * Toute requête HTTP sortante depuis le serveur Scouter doit être validée par
 * ce helper avant d'être envoyée, sinon un utilisateur peut faire crawler des
 * URLs internes (loopback 127.0.0.1, réseau privé, AWS metadata 169.254.169.254,
 * services Docker internes, etc.) et exfiltrer des secrets.
 *
 * Usage typique :
 *   SafeHttp::validate($url);             // throw RuntimeException si l'URL est dangereuse
 *   $ch = curl_init($url);
 *   SafeHttp::applyCurlSecurity($ch);     // restrict protocoles à http(s)
 *   $resp = curl_exec($ch);
 *   SafeHttp::validateFinalIp($ch);       // check que les redirects ne sont pas allés ailleurs
 *
 * Pour les setups particuliers où on doit autoriser des IPs privées (crawl interne
 * dans un Docker, dev local, etc.) : env var SCOUTER_ALLOW_PRIVATE_IPS=true bypass
 * toutes les vérifications.
 *
 * @package    Scouter
 * @subpackage Util
 */
class SafeHttp
{
    /**
     * Valide qu'une URL est sûre à fetch côté serveur.
     *
     * Vérifie : (1) URL bien formée, (2) scheme http/https uniquement,
     * (3) toutes les IPs résolues sont publiques (pas loopback/privé/link-local/multicast).
     *
     * @throws \RuntimeException si l'URL est dangereuse
     */
    public static function validate(string $url): void
    {
        if (self::isBypassEnabled()) {
            return;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException("URL invalide : {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException("Scheme non autorisé : {$scheme} (seuls http/https sont permis)");
        }

        $host = $parts['host'];
        $ips = self::resolveHost($host);
        if (empty($ips)) {
            throw new \RuntimeException("Impossible de résoudre l'hôte : {$host}");
        }
        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                throw new \RuntimeException(
                    "L'hôte {$host} résout vers une IP privée ({$ip}) — bloqué (protection SSRF)"
                );
            }
        }
    }

    /**
     * Applique les options curl de sécurité : restreint les protocoles à http/https,
     * y compris pour les redirections suivies par CURLOPT_FOLLOWLOCATION.
     *
     * Ça empêche un attaquant d'utiliser file://, gopher://, dict://, etc. via
     * une URL initiale http(s) qui redirige vers un schéma dangereux.
     *
     * @param \CurlHandle|resource $ch handle curl
     */
    public static function applyCurlSecurity($ch): void
    {
        $protos = self::allowedProtocolsBitmask();
        // @ pour rester compatible avec d'anciennes versions de libcurl sans ces constantes
        @curl_setopt($ch, CURLOPT_PROTOCOLS, $protos);
        @curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $protos);
    }

    /**
     * Vérifie que l'IP finale (après redirections) est toujours publique.
     * À appeler APRÈS curl_exec. Catch les attaques par redirect type
     * "302 → http://169.254.169.254/..." que validate() ne peut pas anticiper.
     *
     * @param \CurlHandle|resource $ch handle curl, post-execution
     * @throws \RuntimeException si l'IP finale est privée
     */
    public static function validateFinalIp($ch): void
    {
        if (self::isBypassEnabled()) {
            return;
        }
        $ip = @curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        if (!empty($ip) && self::isPrivateIp($ip)) {
            throw new \RuntimeException(
                "L'IP finale après redirection est privée ({$ip}) — bloqué (protection SSRF)"
            );
        }
    }

    /**
     * Résout un hostname en liste d'IPs (v4 + v6). Si l'entrée est déjà une IP,
     * la retourne telle quelle.
     *
     * @return string[]
     */
    private static function resolveHost(string $host): array
    {
        // L'host peut être directement une IP littérale (ex: "127.0.0.1" ou "[::1]")
        $cleanHost = trim($host, '[]');
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            return [$cleanHost];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records) {
            foreach ($records as $rec) {
                if (!empty($rec['ip']))   $ips[] = $rec['ip'];    // A record (IPv4)
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];  // AAAA record
            }
        }
        // Fallback IPv4 si dns_get_record a foiré (selon la config DNS du conteneur)
        if (empty($ips)) {
            $v4 = @gethostbynamel($host);
            if ($v4) $ips = $v4;
        }
        return array_unique($ips);
    }

    /**
     * True si l'IP est dans une plage privée / loopback / link-local / réservée / multicast.
     *
     * IPv4 : utilise les flags built-in PHP (couvre 127/8, 10/8, 172.16/12, 192.168/16,
     *        169.254/16, 0/8, 192/24, 198.18/15, 240/4, 255.255.255.255/32).
     * IPv6 : check manuel des ranges critiques (::1, fc00::/7, fe80::/10, ff00::/8).
     */
    public static function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // NO_PRIV_RANGE + NO_RES_RANGE = IP publique routable
            return !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::isIpv6Private($ip);
        }
        // Inparseable = on bloque par défaut
        return true;
    }

    private static function isIpv6Private(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false || strlen($binary) !== 16) return true;
        // ::1 (loopback)
        if ($binary === str_repeat("\x00", 15) . "\x01") return true;
        // :: (unspecified)
        if ($binary === str_repeat("\x00", 16)) return true;
        $b0 = ord($binary[0]);
        $b1 = ord($binary[1]);
        // fe80::/10 (link-local)
        if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) return true;
        // fc00::/7 (unique local)
        if (($b0 & 0xfe) === 0xfc) return true;
        // ff00::/8 (multicast)
        if ($b0 === 0xff) return true;
        // IPv4-mapped ::ffff:0:0/96 — revalider via la routine IPv4
        if (substr($binary, 0, 10) === str_repeat("\x00", 10) && substr($binary, 10, 2) === "\xff\xff") {
            $v4 = @inet_ntop(substr($binary, 12, 4));
            return $v4 ? self::isPrivateIp($v4) : true;
        }
        return false;
    }

    private static function isBypassEnabled(): bool
    {
        $env = getenv('SCOUTER_ALLOW_PRIVATE_IPS');
        return $env === 'true' || $env === '1';
    }

    private static function allowedProtocolsBitmask(): int
    {
        $http  = defined('CURLPROTO_HTTP')  ? CURLPROTO_HTTP  : 1;
        $https = defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2;
        return $http | $https;
    }
}
