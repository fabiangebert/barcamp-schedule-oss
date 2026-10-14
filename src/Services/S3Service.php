<?php

namespace App\Services;

use AsyncAws\S3\S3Client;
use AsyncAws\S3\ValueObject\Awaitable;
use AsyncAws\Core\Result;

class S3Service
{
    private static ?S3Client $client = null;
    private static ?string $bucket = null;

    public static function isConfigured(): bool
    {
        return (bool)getenv('S3_ENDPOINT');
    }

    public static function getClient(): S3Client
    {
        if (self::$client === null) {
            self::$client = new S3Client([
                'endpoint' => getenv('S3_ENDPOINT'),
                'accessKeyId' => getenv('S3_ACCESS_KEY'),
                'accessKeySecret' => getenv('S3_SECRET_KEY'),
                'region' => getenv('S3_REGION') ?: 'fr-par',
            ]);
            self::$bucket = getenv('S3_BUCKET');
        }
        return self::$client;
    }

    public static function getBucket(): string
    {
        if (self::$bucket === null) {
            self::getClient();
        }
        return self::$bucket ?: '';
    }

    public static function put(string $key, string $body, string $contentType = 'application/octet-stream'): Result
    {
        return self::getClient()->putObject([
            'Bucket' => self::getBucket(),
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $contentType,
            'ACL' => 'public-read',
        ]);
    }

    public static function get(string $key): ?string
    {
        $result = self::getClient()->getObject([
            'Bucket' => self::getBucket(),
            'Key' => $key,
        ]);
        return $result->getBody()->getContentAsString();
    }

    public static function delete(string $key): Result
    {
        return self::getClient()->deleteObject([
            'Bucket' => self::getBucket(),
            'Key' => $key,
        ]);
    }

    public static function getPublicUrl(string $key): string
    {
        $endpoint = rtrim(getenv('S3_ENDPOINT') ?: '', '/');
        return $endpoint . '/' . self::getBucket() . '/' . ltrim($key, '/');
    }
}
