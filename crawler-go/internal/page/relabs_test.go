package page

import "testing"

// Golden values generated with PHP's Page::rel2abs (see /tmp/relabs_ref.php).
func TestRel2Abs(t *testing.T) {
	const b = "https://example.com/blog/post.html"
	cases := []struct{ base, rel, want string }{
		{b, "page2.html", "https://example.com/blog/page2.html"},
		{b, "/about", "https://example.com/about"},
		{b, "../contact", "https://example.com/contact"},
		{b, "./same", "https://example.com/blog/same"},
		{b, "sub/deep.html", "https://example.com/blog/sub/deep.html"},
		{b, "https://other.com/x", "https://other.com/x"},
		{b, "//cdn.example.com/a.js", "https://cdn.example.com/a.js"},
		{b, "?q=1", "https://example.com/blog/post.html?q=1"},
		{b, "#frag", "https://example.com/blog/post.html#frag"},
		{b, "../../root", "https://example.com/root"},
		{b, "folder/", "https://example.com/blog/folder"},
		{b, "..", "https://example.com/"},
		{b, ".", "https://example.com/blog/"},
		{b, "a/b/../c", "https://example.com/blog/a/c"},
		{b, "image.png?v=2", "https://example.com/blog/image.png?v=2"},
		{"https://example.com/", "page.html", "https://example.com/page.html"},
		{"https://example.com/", "sub/", "https://example.com/sub"},
		{"https://example.com/", "../up", "https://example.com/up"},
		{"https://example.com/", "deep/a/b.html", "https://example.com/deep/a/b.html"},
	}
	for _, c := range cases {
		if got := rel2abs(c.base, c.rel); got != c.want {
			t.Errorf("rel2abs(%q, %q) = %q, want %q", c.base, c.rel, got, c.want)
		}
	}
}
