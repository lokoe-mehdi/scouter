<?php

use App\Storage\LocalStorage;
use App\Storage\S3Storage;
use App\Storage\Storage;

it('streams a file upload through the local backend', function () {
    $root = sys_get_temp_dir() . '/scouter-export-test-' . getmypid() . '-' . uniqid();
    Storage::set(new LocalStorage($root));

    $src = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($src, "a;b;c\n1;2;3\n");
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
    $this->markTestSkipped('Requires PostgreSQL connection');
});

it('prunes expired exports and removes their blob', function () {
    $this->markTestSkipped('Requires PostgreSQL connection');
});
