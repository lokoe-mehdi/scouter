<?php

use App\Storage\LocalStorage;
use App\Storage\S3Storage;
use App\Storage\Storage;
use App\Export\ExportService;
use App\Database\PostgresDatabase;

it('streams a file upload through the local backend', function () {
    $root = sys_get_temp_dir() . '/scouter-export-test-' . getmypid() . '-' . uniqid();
    Storage::set(new LocalStorage($root));

    $src = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($src, "a;b;c\n1;2;3\n");
    // putFile consumes the temp (rename into place) — no manual cleanup after.
    expect(Storage::instance()->putFile('export/1/test.csv', $src, 'text/csv'))->toBeTrue();
    expect(Storage::instance()->get('export/1/test.csv'))->toContain('1;2;3');

    Storage::set(null);
});

it('returns no presigned URL for the local backend (app streams instead)', function () {
    $root = sys_get_temp_dir() . '/scouter-export-test-' . getmypid() . '-' . uniqid();
    Storage::set(new LocalStorage($root));

    expect(Storage::instance()->presignedGetUrl('export/1/test.csv', 3600))->toBeNull();

    Storage::set(null);
});

it('builds a valid-looking presigned S3 URL with a 24h expiry', function () {
    $s3 = new S3Storage('my-bucket', 'AKIDEXAMPLE', 'secret', '', 'eu-west-3', false, '');
    $url = $s3->presignedGetUrl('export/42/report.csv', 86400, [
        'response-content-disposition' => 'attachment; filename="report.csv"',
    ]);
    expect($url)->toContain('https://my-bucket.s3.eu-west-3.amazonaws.com/export/42/report.csv');
    expect($url)->toContain('X-Amz-Algorithm=AWS4-HMAC-SHA256');
    expect($url)->toContain('X-Amz-Expires=86400');
    expect($url)->toContain('X-Amz-Signature=');
    expect($url)->toContain('response-content-disposition=');
});

it('uses path-style addressing when configured (MinIO/R2)', function () {
    $s3 = new S3Storage('bucket', 'AK', 'sk', 'https://minio.local:9000', 'us-east-1', true, '');
    $url = $s3->presignedGetUrl('export/1/x.csv', 600);
    expect($url)->toContain('https://minio.local:9000/bucket/export/1/x.csv');
});

it('creates a pending export and enqueues an export job', function () {
    $root = sys_get_temp_dir() . '/scouter-export-test-' . getmypid() . '-' . uniqid();
    Storage::set(new LocalStorage($root));
    $db = PostgresDatabase::getInstance()->getConnection();

    $crawl = (object) ['id' => 991234, 'project_id' => 880099, 'domain' => 'example.com', 'path' => '/test/exp'];
    $row = (new ExportService())->create(778899, $crawl, 'urls', ['columns' => '["url"]']);

    expect($row['status'])->toBe('pending');
    expect($row['type'])->toBe('urls');
    expect($row['filename'])->toEndWith('.csv');
    expect($row['filename'])->toContain('example.com');
    expect((int)$row['job_id'])->toBeGreaterThan(0);

    // The job is queued with an export: command.
    $job = $db->query("SELECT command, status FROM jobs WHERE id = " . (int)$row['job_id'])->fetch(PDO::FETCH_ASSOC);
    expect($job['command'])->toBe("export:{$row['id']}");
    expect($job['status'])->toBe('queued');

    // Cleanup
    $db->exec("DELETE FROM exports WHERE id = " . (int)$row['id']);
    $db->exec("DELETE FROM jobs WHERE command LIKE 'export:%' AND project_name = 'Export urls'");
    Storage::set(null);
});

it('prunes expired exports and removes their blob', function () {
    $root = sys_get_temp_dir() . '/scouter-export-test-' . getmypid() . '-' . uniqid();
    Storage::set(new LocalStorage($root));
    $db = PostgresDatabase::getInstance()->getConnection();

    // Seed a ready export already past its TTL. object_key follows the prune
    // convention export/<id>/<file>, so insert first to learn the id.
    $stmt = $db->prepare("
        INSERT INTO exports (user_id, crawl_id, type, label, params, status, filename, created_at, expires_at)
        VALUES (778899, 1, 'urls', 'old', '{}', 'ready', 'old.csv', NOW() - INTERVAL '2 days', NOW() - INTERVAL '1 day')
        RETURNING id
    ");
    $stmt->execute();
    $id = (int)$stmt->fetchColumn();
    $key = "export/{$id}/old.csv";
    Storage::instance()->put($key, 'x;y');
    $db->exec("UPDATE exports SET object_key = '" . $key . "' WHERE id = $id");

    $removed = (new ExportService())->pruneExpired();
    expect($removed)->toBeGreaterThanOrEqual(1);

    // Row gone, blob gone.
    $exists = $db->query("SELECT COUNT(*) FROM exports WHERE id = $id")->fetchColumn();
    expect((int)$exists)->toBe(0);
    expect(Storage::instance()->get($key))->toBeNull();

    // Cleanup
    $db->exec("DELETE FROM exports WHERE user_id = 778899");
    Storage::set(null);
});
