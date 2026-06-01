<?php

namespace App\Storage;

/**
 * Resolves and caches the process-wide blob {@see StorageInterface} from the
 * environment: an {@see S3Storage} when S3_BUCKET + S3_ACCESS_KEY_ID +
 * S3_SECRET_ACCESS_KEY are all set, otherwise a {@see LocalStorage} rooted at
 * STORAGE_PATH (default: <repo>/storage).
 *
 * Mirrors the crawler-go `internal/storage.New` selection so writer and readers
 * agree on where the HTML lives.
 *
 * @package    Scouter
 * @subpackage Storage
 */
class Storage
{
    private static ?StorageInterface $instance = null;

    public static function instance(): StorageInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $bucket = trim((string)getenv('S3_BUCKET'));
        $accessKey = trim((string)getenv('S3_ACCESS_KEY_ID'));
        $secretKey = trim((string)getenv('S3_SECRET_ACCESS_KEY'));

        if ($bucket !== '' && $accessKey !== '' && $secretKey !== '') {
            self::$instance = new S3Storage(
                $bucket,
                $accessKey,
                $secretKey,
                trim((string)getenv('S3_ENDPOINT')),
                trim((string)getenv('S3_REGION')),
                self::envBool('S3_USE_PATH_STYLE'),
                trim((string)getenv('S3_PREFIX'))
            );
        } else {
            $path = trim((string)getenv('STORAGE_PATH'));
            if ($path === '') {
                $path = dirname(__DIR__, 2) . '/storage';
            }
            self::$instance = new LocalStorage($path);
        }

        return self::$instance;
    }

    /** Override the backend — used by tests. */
    public static function set(?StorageInterface $store): void
    {
        self::$instance = $store;
    }

    private static function envBool(string $key): bool
    {
        return in_array(strtolower(trim((string)getenv($key))), ['1', 'true', 'yes', 'on'], true);
    }
}
