<?php

namespace Tests;

use App\App;
use Doctrine\DBAL\Exception;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class DownloadRoutesTest extends BaseTestCase
{
    protected \Slim\App $app;

    /**
     * @throws Exception
     */
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

    public function testDownloadsRouteReturns404IfFileNotFound()
    {
        $request = $this->createRequest('/api/uploads/nonexistent.jpg');
        $response = $this->app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testDownloadsRouteReturnsImage()
    {
        // Create a dummy image file
        $filename = 'test.jpg';
        $uploadsDir = getenv('UPLOADS_DIR');
        $filePath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($filePath, 'fake image content');

        $request = $this->createRequest("/api/uploads/$filename");
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('image/jpeg', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('fake image content', (string)$response->getBody());
    }

    public function testDownloadsRouteReturnsPdf()
    {
        // Create a dummy PDF file
        $filename = 'test.pdf';
        $uploadsDir = getenv('UPLOADS_DIR');
        $filePath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($filePath, 'fake pdf content');

        $request = $this->createRequest("/api/uploads/$filename");
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('fake pdf content', (string)$response->getBody());
    }
}
