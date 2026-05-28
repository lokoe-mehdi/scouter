<?php

namespace App\Storage;

class Storage
{
    private static ?StorageInterface $instance = null;

    public static function set(?StorageInterface $storage): void
    {
        self::$instance = $storage;
    }

    public static function instance(): StorageInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = getenv('STORAGE_DRIVER') ?: 'local';

        if ($driver === 's3') {
            self::$instance = new S3Storage(
                (string)getenv('S3_BUCKET'),
                (string)getenv('S3_ACCESS_KEY'),
                (string)getenv('S3_SECRET_KEY'),
                (string)getenv('S3_ENDPOINT'),
                (string)(getenv('S3_REGION') ?: 'us-east-1'),
                (bool)getenv('S3_PATH_STYLE'),
                (string)getenv('S3_PREFIX')
            );
        } else {
            $root = (string)(getenv('STORAGE_LOCAL_ROOT') ?: sys_get_temp_dir() . '/scouter-storage');
            self::$instance = new LocalStorage($root);
        }

        return self::$instance;
    }
}
