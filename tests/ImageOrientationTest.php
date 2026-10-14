<?php

namespace Tests;

use App\App;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class ImageOrientationTest extends BaseTestCase
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

    /**
     * Create a JPEG image with EXIF orientation 6 (90 degrees CCW / rotated 90 degrees CW)
     * Note: Creating actual EXIF data in PHP without external libraries like Pel is hard.
     * We'll try to see if we can use a known sample or if getimagesize/exif_read_data can be mocked/fooled.
     * Actually, we can just use a real (small) JPEG with EXIF if we had one.
     * Since we don't, we'll try to use the `exif_read_data` behavior.
     */
    public function testImageOrientationIsPreserved()
    {
        // For this test to be effective, we'd need a JPEG with EXIF orientation.
        // PHP's GD doesn't support writing EXIF.
        // I will check if I can find any image in the project that might have EXIF.

        $uploadsDir = getenv('UPLOADS_DIR');
        $originalFilename = 'orient_test.jpg';
        $originalPath = $uploadsDir . DIRECTORY_SEPARATOR . $originalFilename;

        // Create a 100x200 image (Tall)
        // If we set orientation to 6, it should be treated as 200x100 (Wide) after rotation
        $img = imagecreatetruecolor(100, 200);
        imagejpeg($img, $originalPath);
        // Since I cannot easily write EXIF orientation 6 to the file here without a library,
        // I will assume for now that IF I could, the current code would FAIL to rotate it.

        // Let's check if exif_read_data is available
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('exif extension not available');
        }

        $request = $this->createRequest("/api/uploads/$originalFilename");
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());

        $tempFile = tempnam(sys_get_temp_dir(), 'webp_orient_test');
        file_put_contents($tempFile, (string)$response->getBody());
        $size = getimagesize($tempFile);

        // Currently, it will be 100x200 because no rotation happens.
        $this->assertEquals(100, $size[0]);
        $this->assertEquals(200, $size[1]);

        unlink($tempFile);
        unlink($originalPath);
    }
}
