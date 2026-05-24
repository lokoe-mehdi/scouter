package page

import (
	"net/url"
	"regexp"
	"strings"
)

// detectIsHtml ports Page::detectIsHtml: multi-heuristic check that the body is
// real HTML (not PDF/image/binary), used to set pages.is_html.
var nonHTMLExtensions = map[string]bool{
	"pdf": true, "doc": true, "docx": true, "xls": true, "xlsx": true, "ppt": true, "pptx": true,
	"odt": true, "ods": true, "odp": true,
	"jpg": true, "jpeg": true, "png": true, "gif": true, "bmp": true, "webp": true, "svg": true,
	"ico": true, "tiff": true, "tif": true,
	"mp3": true, "wav": true, "ogg": true, "flac": true, "aac": true, "m4a": true,
	"mp4": true, "avi": true, "mov": true, "wmv": true, "mkv": true, "webm": true, "flv": true,
	"zip": true, "rar": true, "7z": true, "tar": true, "gz": true, "bz2": true,
	"exe": true, "msi": true, "dmg": true, "apk": true, "deb": true, "rpm": true,
	"css": true, "js": true, "json": true, "xml": true, "rss": true, "atom": true,
	"ttf": true, "woff": true, "woff2": true, "eot": true, "otf": true,
}

var nonHTMLTypes = []string{
	"application/pdf", "application/zip", "application/octet-stream",
	"application/javascript", "application/json", "application/xml",
	"image/", "audio/", "video/", "font/",
	"text/css", "text/javascript", "text/plain", "text/xml",
}

var binarySignatures = []string{
	"\x25\x50\x44\x46", "\xFF\xD8\xFF", "\x89\x50\x4E\x47", "\x47\x49\x46\x38",
	"\x50\x4B\x03\x04", "\x52\x61\x72\x21", "\x1F\x8B", "\x42\x4D",
	"\x00\x00\x00", "\x49\x44\x33", "\xFF\xFB", "\x4F\x67\x67\x53",
}

var (
	htmlTagsRe  = regexp.MustCompile(`(?i)<(!DOCTYPE|html|head|body|div|p|a|span|script|link|meta)`)
	printableRe = regexp.MustCompile(`[\x20-\x7E\x0A\x0D\x09]`)
)

func detectIsHTML(rawURL, contentType, content string) bool {
	// 1. URL extension
	if u, err := url.Parse(rawURL); err == nil {
		p := u.Path
		if dot := strings.LastIndexByte(p, '.'); dot >= 0 {
			ext := strings.ToLower(p[dot+1:])
			if slash := strings.IndexByte(ext, '/'); slash >= 0 {
				ext = ext[:slash]
			}
			if nonHTMLExtensions[ext] {
				return false
			}
		}
	}

	// 2. content-type
	ct := strings.ToLower(contentType)
	for _, t := range nonHTMLTypes {
		if strings.Contains(ct, t) {
			return false
		}
	}
	if strings.Contains(ct, "text/html") || strings.Contains(ct, "application/xhtml") {
		return true
	}

	// 3-5. body heuristics
	if content != "" {
		first := content
		if len(first) > 16 {
			first = first[:16]
		}
		for _, sig := range binarySignatures {
			if strings.HasPrefix(first, sig) {
				return false
			}
		}
		if htmlTagsRe.MatchString(content) {
			return true
		}
		sampleSize := 1000
		if len(content) < sampleSize {
			sampleSize = len(content)
		}
		sample := content[:sampleSize]
		printable := len(printableRe.FindAllString(sample, -1))
		ratio := 0.0
		if sampleSize > 0 {
			ratio = float64(printable) / float64(sampleSize)
		}
		if ratio < 0.8 {
			return false
		}
	}

	return content != ""
}
