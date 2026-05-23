package analysis

import (
	"fmt"
	"net"
	"net/url"
	"os"
	"strings"
)

// SafeHTTP ports app/Util/SafeHttp.php: anti-SSRF validation of outbound URLs.
// Any user-controlled URL must be validated before fetching, to block internal
// targets (loopback, RFC1918, link-local, AWS metadata 169.254.169.254, …).
//
// Bypass for trusted/internal setups: env SCOUTER_ALLOW_PRIVATE_IPS=true|1.

// ssrfBypass mirrors SafeHttp::isBypassEnabled().
func ssrfBypass() bool {
	v := os.Getenv("SCOUTER_ALLOW_PRIVATE_IPS")
	return v == "true" || v == "1"
}

// ValidateURL throws (returns error) if the URL is unsafe to fetch server-side:
// malformed, non-http(s) scheme, or any resolved IP being private/reserved.
func ValidateURL(rawurl string) error {
	if ssrfBypass() {
		return nil
	}
	u, err := url.Parse(rawurl)
	if err != nil || u.Scheme == "" || u.Hostname() == "" {
		return fmt.Errorf("URL invalide : %s", rawurl)
	}
	scheme := strings.ToLower(u.Scheme)
	if scheme != "http" && scheme != "https" {
		return fmt.Errorf("scheme non autorisé : %s (seuls http/https sont permis)", scheme)
	}
	ips, err := resolveHost(u.Hostname())
	if err != nil || len(ips) == 0 {
		return fmt.Errorf("impossible de résoudre l'hôte : %s", u.Hostname())
	}
	for _, ip := range ips {
		if IsPrivateIP(ip) {
			return fmt.Errorf("l'hôte %s résout vers une IP privée (%s) — bloqué (SSRF)", u.Hostname(), ip)
		}
	}
	return nil
}

// ValidateIPString checks a final/resolved IP (post-redirect) the same way
// SafeHttp::validateFinalIp does.
func ValidateIPString(ip string) error {
	if ssrfBypass() {
		return nil
	}
	parsed := net.ParseIP(ip)
	if parsed != nil && IsPrivateIP(parsed) {
		return fmt.Errorf("l'IP finale après redirection est privée (%s) — bloqué (SSRF)", ip)
	}
	return nil
}

func resolveHost(host string) ([]net.IP, error) {
	host = strings.Trim(host, "[]")
	if ip := net.ParseIP(host); ip != nil {
		return []net.IP{ip}, nil
	}
	return net.LookupIP(host)
}

// IsPrivateIP reports whether ip is loopback/private/link-local/reserved/multicast.
// Covers the same ground as the PHP filter flags (NO_PRIV_RANGE|NO_RES_RANGE) plus
// the manual IPv6 ranges; errs on the side of blocking unknown shapes.
func IsPrivateIP(ip net.IP) bool {
	if ip == nil {
		return true
	}
	if ip.IsLoopback() || ip.IsPrivate() || ip.IsUnspecified() ||
		ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() || ip.IsMulticast() {
		return true
	}
	if v4 := ip.To4(); v4 != nil {
		switch {
		case v4[0] == 0: // 0.0.0.0/8
			return true
		case v4[0] == 100 && v4[1]&0xc0 == 64: // 100.64/10 CGNAT (reserved)
			return true
		case v4[0] == 198 && (v4[1] == 18 || v4[1] == 19): // 198.18/15 benchmarking
			return true
		case v4[0]&0xf0 == 240: // 240/4 reserved
			return true
		case v4[0] == 192 && v4[1] == 0 && v4[2] == 2: // 192.0.2/24 TEST-NET
			return true
		}
	}
	return false
}
