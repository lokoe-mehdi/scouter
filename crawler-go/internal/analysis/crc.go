package analysis

import (
	"fmt"
	"hash/crc32"
)

// CRC-32/BZIP2 table (poly 0x04C11DB7, MSB-first, no reflection).
//
// IMPORTANT parity note: PHP's hash('crc32', $s) is CRC-32/BZIP2 (non-reflected),
// which is what Page IDs use — NOT the standard IEEE/zip crc32.
//   - hash('crc32',  "abc") == "73bb8c64"  ← page id  → bzip2Table here
//   - crc32("abc")          == 0x352441c2  ← simhash   → hash/crc32 IEEE
var bzip2Table = makeBzip2Table()

func makeBzip2Table() [256]uint32 {
	const poly = 0x04C11DB7
	var t [256]uint32
	for i := 0; i < 256; i++ {
		crc := uint32(i) << 24
		for j := 0; j < 8; j++ {
			if crc&0x80000000 != 0 {
				crc = (crc << 1) ^ poly
			} else {
				crc <<= 1
			}
		}
		t[i] = crc
	}
	return t
}

// crc32Bzip2 computes CRC-32/BZIP2 (init 0xFFFFFFFF, xorout 0xFFFFFFFF,
// refin/refout false) — the variant PHP exposes as hash('crc32').
func crc32Bzip2(b []byte) uint32 {
	crc := uint32(0xFFFFFFFF)
	for _, c := range b {
		crc = (crc << 8) ^ bzip2Table[byte(crc>>24)^c]
	}
	return crc ^ 0xFFFFFFFF
}

// PageID reproduces PHP hash('crc32', $url, false): the 8-char lowercase hex of
// the CRC-32/BZIP2 of the URL. PHP emits the 4 result bytes in REVERSED order
// relative to the conventional big-endian integer (e.g. CRC-32/BZIP2("123456789")
// is 0xFC891918 but PHP prints "181989fc"), so we byte-swap before formatting.
// This is the CHAR(8) primary key shared by pages/links/html — it MUST match
// the PHP output exactly or the whole crawl graph breaks.
func PageID(url string) string {
	v := crc32Bzip2([]byte(url))
	swapped := (v&0x000000FF)<<24 | (v&0x0000FF00)<<8 | (v&0x00FF0000)>>8 | (v&0xFF000000)>>24
	return fmt.Sprintf("%08x", swapped)
}

// crc32IEEE reproduces PHP crc32($s) (standard reflected zip CRC-32), used only
// inside Simhash.hash64.
func crc32IEEE(b []byte) uint32 {
	return crc32.ChecksumIEEE(b)
}
