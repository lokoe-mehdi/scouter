<?php

use App\Storage\LocalStorage;
use App\Storage\Storage;
use App\Storage\HtmlStore;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/scouter-storage-test-' . getmypid() . '-' . uniqid();
    Storage::set(new LocalStorage($this->root));
});

afterEach(function () {
    Storage::set(null); // restore env-based resolution for other tests
    // best-effort cleanup
    $it = @glob($this->root . '/html/*/*');
    if ($it) {
        foreach ($it as $f) {
            @unlink($f);
        }
    }
});

it('round-trips a blob through the local backend', function () {
    $store = Storage::instance();
    expect($store->kind())->toBe('local');

    $key = HtmlStore::key(7, 'cafef00d');
    expect($store->get($key))->toBeNull();

    expect($store->put($key, 'hello'))->toBeTrue();
    expect($store->get($key))->toBe('hello');

    // overwrite is idempotent
    expect($store->put($key, 'world'))->toBeTrue();
    expect($store->get($key))->toBe('world');
});

it('deletes a whole crawl prefix without touching other crawls', function () {
    $store = Storage::instance();
    $store->put(HtmlStore::key(1, 'aaaa'), 'x');
    $store->put(HtmlStore::key(1, 'bbbb'), 'y');
    $store->put(HtmlStore::key(2, 'cccc'), 'z');

    $removed = $store->deletePrefix('html/1/');
    expect($removed)->toBe(2);
    expect($store->get(HtmlStore::key(1, 'aaaa')))->toBeNull();
    expect($store->get(HtmlStore::key(2, 'cccc')))->toBe('z');
});

it('reads gzip-compressed HTML written the way the Go crawler writes it', function () {
    // The crawler stores gzip bytes; HtmlStore must return them decompressed.
    $html = '<html><body><h1>Bonjour</h1></body></html>';
    Storage::instance()->put(HtmlStore::key(42, 'deadbeef'), gzencode($html));

    // Blob hit → no DB access needed (pass null, it must not be touched).
    $raw = HtmlStore::fetch(42, 'deadbeef', true, null);
    expect($raw)->toBe($html);
});

it('falls back to the database when no blob exists', function () {
    // No blob written for this key → HtmlStore must query the DB handle.
    $stmt = new class {
        public function execute($params) { return true; }
        public function fetchColumn() { return 'RAW HTML FROM DB'; }
    };
    $db = new class($stmt) {
        public function __construct(private $stmt) {}
        public function prepare($sql) { return $this->stmt; }
    };

    // useCh=true → the stored column is already raw, returned as-is.
    $raw = HtmlStore::fetch(99, 'feedface', true, $db);
    expect($raw)->toBe('RAW HTML FROM DB');
});
