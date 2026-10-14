<?php

namespace Tests;

use App\App;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class ImageResizeTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    private function createRequest(string $path): Request
    {
        $uri = new Uri('', '', null, $path);
        $headersObj = new Headers();
        $handle = fopen('php://temp', 'w+');
        return new Request('GET', $uri, $headersObj, [], $_SERVER, new Stream($handle));
    }

    private function createLargeImage(string $path, int $width = 3000, int $height = 2000): void
    {
        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);
        imagejpeg($img, $path);
        imagedestroy($img);
    }

    public function testImageDownscaleAndWebpConversion()
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'test_img_src');
        $this->createLargeImage($tempSource);

        $uploadedFile = new \Slim\Psr7\UploadedFile(
            $tempSource,
            'large_image.jpg',
            'image/jpeg',
            filesize($tempSource)
        );

        $uploadService = new \App\Services\UploadService();
        $path = $uploadService->handleUpload($uploadedFile);

        $request = $this->createRequest($path);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('image/webp', $response->getHeaderLine('Content-Type'));

        // Save the response body to a temporary file to check dimensions
        $tempFile = tempnam(sys_get_temp_dir(), 'webp_test');
        file_put_contents($tempFile, (string)$response->getBody());

        $size = getimagesize($tempFile);
        $this->assertNotFalse($size, "Response body should be a valid image");
        $this->assertEquals(IMAGETYPE_WEBP, $size[2]);

        $width = $size[0];
        $height = $size[1];

        $this->assertLessThanOrEqual(2048, $width);
        $this->assertLessThanOrEqual(2048, $height);

        // Original was 3000x2000, so it should be downscaled to 2048x1365 (approx)
        $this->assertEquals(2048, $width);
        $this->assertEquals(1365, $height);

        // Check if the downscaled file exists in the uploads directory
        $uploadsDir = getenv('UPLOADS_DIR');
        $filename = basename($path);
        $downscaledPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename . '.downscaled.webp';
        $this->assertFileExists($downscaledPath);

        unlink($tempFile);
        if (file_exists($tempSource)) {
            unlink($tempSource);
        }
    }

    public function testTallImageDownscale()
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'test_img_src');
        $this->createLargeImage($tempSource, 2000, 3000);

        $uploadedFile = new \Slim\Psr7\UploadedFile(
            $tempSource,
            'tall_image.jpg',
            'image/jpeg',
            filesize($tempSource)
        );

        $uploadService = new \App\Services\UploadService();
        $path = $uploadService->handleUpload($uploadedFile);

        $request = $this->createRequest($path);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('image/webp', $response->getHeaderLine('Content-Type'));

        $tempFile = tempnam(sys_get_temp_dir(), 'webp_test');
        file_put_contents($tempFile, (string)$response->getBody());

        $size = getimagesize($tempFile);
        $this->assertEquals(1365, $size[0]);
        $this->assertEquals(2048, $size[1]);

        unlink($tempFile);
        if (file_exists($tempSource)) {
            unlink($tempSource);
        }
    }

    public function testSmallImageOnlyConversion()
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'test_img_src');
        $this->createLargeImage($tempSource, 500, 400);

        $uploadedFile = new \Slim\Psr7\UploadedFile(
            $tempSource,
            'small_image.jpg',
            'image/jpeg',
            filesize($tempSource)
        );

        $uploadService = new \App\Services\UploadService();
        $path = $uploadService->handleUpload($uploadedFile);

        $request = $this->createRequest($path);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('image/webp', $response->getHeaderLine('Content-Type'));

        $tempFile = tempnam(sys_get_temp_dir(), 'webp_test');
        file_put_contents($tempFile, (string)$response->getBody());

        $size = getimagesize($tempFile);
        $this->assertEquals(500, $size[0]);
        $this->assertEquals(400, $size[1]);
        $this->assertEquals(IMAGETYPE_WEBP, $size[2]);

        unlink($tempFile);
        if (file_exists($tempSource)) {
            unlink($tempSource);
        }
    }

    public function testImageCaching()
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'test_img_src');
        $this->createLargeImage($tempSource);

        $uploadedFile = new \Slim\Psr7\UploadedFile(
            $tempSource,
            'caching_test.jpg',
            'image/jpeg',
            filesize($tempSource)
        );

        $uploadService = new \App\Services\UploadService();
        $path = $uploadService->handleUpload($uploadedFile);

        $uploadsDir = getenv('UPLOADS_DIR');
        $filename = basename($path);
        $downscaledPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename . '.downscaled.webp';

        $this->assertFileExists($downscaledPath);
        $mtime1 = filemtime($downscaledPath);

        // Sleep to ensure filemtime would change if updated
        sleep(1);

        // Request should use the cached version
        $request = $this->createRequest($path);
        $this->app->handle($request);

        $mtime2 = filemtime($downscaledPath);
        $this->assertEquals($mtime1, $mtime2, "Cached version should not have been updated");

        if (file_exists($tempSource)) {
            unlink($tempSource);
        }
    }

    public function testNonImageFilesAreNotConverted()
    {
        $uploadsDir = getenv('UPLOADS_DIR');
        $filename = 'test.pdf';
        $filePath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($filePath, 'fake pdf content');

        $request = $this->createRequest("/api/uploads/$filename");
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('fake pdf content', (string)$response->getBody());
    }
}
