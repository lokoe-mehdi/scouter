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

        $accessKey = getenv('AWS_ACCESS_KEY_ID') ?: getenv('S3_ACCESS_KEY') ?: '';
        if ($accessKey !== '') {
            self::$instance = new S3Storage(
                getenv('S3_BUCKET') ?: 'scouter',
                $accessKey,
                getenv('AWS_SECRET_ACCESS_KEY') ?: getenv('S3_SECRET_KEY') ?: '',
                getenv('S3_ENDPOINT') ?: '',
                getenv('S3_REGION') ?: getenv('AWS_REGION') ?: 'us-east-1',
                (bool)(getenv('S3_PATH_STYLE') ?: false),
                getenv('S3_CDN_URL') ?: ''
            );
        } else {
            $root = getenv('STORAGE_PATH') ?: (__DIR__ . '/../../storage');
            self::$instance = new LocalStorage($root);
        }

        return self::$instance;
    }
}
