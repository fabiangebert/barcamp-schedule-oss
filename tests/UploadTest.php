<?php

namespace Tests;

use App\App;
use App\Auth;
use App\Schedule;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;
use Slim\Psr7\UploadedFile;

class UploadTest extends BaseTestCase
{
    protected \Slim\App $app;
    protected ?string $hashedId;
    protected string $testUploadDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
        $this->hashedId = Schedule::create('test-camp', [
            'rooms' => ['Room 1'],
            'timeslots' => ['09:00'],
            'sessions' => [
                ['timeslot' => '09:00', 'room' => 'Room 1', 'title' => 'Test Session']
            ]
        ]);

        $this->testUploadDir = sys_get_temp_dir() . '/barcamp_uploads_' . bin2hex(random_bytes(4));
        if (!is_dir($this->testUploadDir)) {
            mkdir($this->testUploadDir, 0777, true);
        }
        putenv("UPLOADS_DIR=" . $this->testUploadDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testUploadDir)) {
            $files = glob($this->testUploadDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->testUploadDir);
        }
        putenv("UPLOADS_DIR");
        parent::tearDown();
    }

    public function testUploadFileSuccess()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload');
        $pngContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
        file_put_contents($tmpFile, $pngContent);

        $uploadedFile = new UploadedFile(
            $tmpFile,
            'test.png',
            'image/png',
            strlen($pngContent)
        );

        $uri = new Uri('', '', null, "/api/schedules/$this->hashedId/upload");
        $headers = new Headers();
        $headers->addHeader('Authorization', "Bearer $jwt");

        $request = new Request('POST', $uri, $headers, [], $_SERVER, new Stream(fopen('php://temp', 'r+')));
        $request = $request->withUploadedFiles(['file' => $uploadedFile]);

        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertStringContainsString('/api/uploads/', $data['path']);

        // Clean up
        $filename = basename($data['path']);
        if (file_exists($this->testUploadDir . '/' . $filename)) {
            unlink($this->testUploadDir . '/' . $filename);
        }
    }

    public function testUploadInvalidMimeType()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload');
        file_put_contents($tmpFile, 'some plain text content masquerading as a png');

        $uploadedFile = new UploadedFile(
            $tmpFile,
            'spoofed.png',
            'image/png',
            strlen('some plain text content masquerading as a png')
        );

        $uri = new Uri('', '', null, "/api/schedules/$this->hashedId/upload");
        $headers = new Headers();
        $headers->addHeader('Authorization', "Bearer $jwt");

        $request = new Request('POST', $uri, $headers, [], $_SERVER, new Stream(fopen('php://temp', 'r+')));
        $request = $request->withUploadedFiles(['file' => $uploadedFile]);

        $response = $this->app->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
        $this->assertStringContainsString('Unsupported MIME type', $data['error']);
    }

    public function testSubmitDocumentationWithUploadedImages()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');

        $image1 = '0123456789abcdef.png';
        $image2 = 'fedcba9876543210.jpg';

        $path1 = $this->testUploadDir . DIRECTORY_SEPARATOR . $image1;
        $path2 = $this->testUploadDir . DIRECTORY_SEPARATOR . $image2;

        file_put_contents($path1, 'test');
        file_put_contents($path2, 'test');

        $payload = [
            'content' => 'Doc with images',
            'images' => ["/api/uploads/$image1", "/api/uploads/$image2"]
        ];

        $uri = new Uri('', '', null, "/api/schedules/$this->hashedId/sessions/09:00/Room 1/documentation");
        $headers = new Headers();
        $headers->addHeader('Authorization', "Bearer $jwt");
        $headers->addHeader('Content-Type', 'application/json');

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write(json_encode($payload));
        $body->rewind();

        $request = new Request('POST', $uri, $headers, [], $_SERVER, $body);

        $response = $this->app->handle($request);

        $bodyContent = (string)$response->getBody();
        $this->assertEquals(200, $response->getStatusCode(), "Response body: " . $bodyContent);
        $data = json_decode($bodyContent, true);
        $this->assertEquals('success', $data['status']);

        $docs = \App\Documentation::getForSession($this->hashedId, '09:00', 'Room 1');
        $this->assertCount(1, $docs);
        $this->assertEquals('Doc with images', $docs[0]['content']);
        $this->assertEquals(["/api/uploads/$image1", "/api/uploads/$image2"], $docs[0]['images']);
    }

    public function testSubmitDocumentationWithNonExistentImage()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');

        $payload = [
            'content' => 'Doc with missing image',
            'images' => ['/api/uploads/0123456789abcdef.png']
        ];

        $uri = new Uri('', '', null, "/api/schedules/$this->hashedId/sessions/09:00/Room 1/documentation");
        $headers = new Headers();
        $headers->addHeader('Authorization', "Bearer $jwt");
        $headers->addHeader('Content-Type', 'application/json');

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write(json_encode($payload));
        $body->rewind();

        $request = new Request('POST', $uri, $headers, [], $_SERVER, $body);

        $response = $this->app->handle($request);

        // Image path format is valid (16 hex chars + png), no file existence check on disk
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSubmitDocumentationWithInvalidImages()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');

        $payload = [
            'content' => 'Doc with malicious images',
            'images' => ['https://malicious.com/exploit.js', '/etc/passwd', '../outside.png']
        ];

        $uri = new Uri('', '', null, "/api/schedules/$this->hashedId/sessions/09:00/Room 1/documentation");
        $headers = new Headers();
        $headers->addHeader('Authorization', "Bearer $jwt");
        $headers->addHeader('Content-Type', 'application/json');

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write(json_encode($payload));
        $body->rewind();

        $request = new Request('POST', $uri, $headers, [], $_SERVER, $body);

        $response = $this->app->handle($request);

        // This currently passes but should ideally fail with 400 Bad Request
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
        $this->assertStringContainsString('Invalid or unsupported image path', $data['error']);
    }

    public function testUploadForbiddenFile()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload');
        file_put_contents($tmpFile, '<svg>...</svg>');

        $uploadedFile = new UploadedFile(
            $tmpFile,
            'malicious.svg',
            'image/svg+xml',
            strlen('<svg>...</svg>')
        );

        $uri = new Uri('', '', null, "/api/schedules/$this->hashedId/upload");
        $headers = new Headers();
        $headers->addHeader('Authorization', "Bearer $jwt");

        $request = new Request('POST', $uri, $headers, [], $_SERVER, new Stream(fopen('php://temp', 'r+')));
        $request = $request->withUploadedFiles(['file' => $uploadedFile]);

        $response = $this->app->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
        $this->assertStringContainsString('Unsupported file type', $data['error']);
    }

    public function testSubmitDocumentationWithForbiddenExtension()
    {
        $jwt = Auth::generateJWT('guest@guest.com', 'guest', 'Test Guest');

        $image1 = '0123456789abcdef.svg';
        $path1 = $this->testUploadDir . DIRECTORY_SEPARATOR . $image1;
        file_put_contents($path1, '<svg></svg>');

        $payload = [
            'content' => 'Doc with svg',
            'images' => ["/api/uploads/$image1"]
        ];

        $uri = new Uri('', '', null, "/api/schedules/$this->hashedId/sessions/09:00/Room 1/documentation");
        $headers = new Headers();
        $headers->addHeader('Authorization', "Bearer $jwt");
        $headers->addHeader('Content-Type', 'application/json');

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write(json_encode($payload));
        $body->rewind();

        $request = new Request('POST', $uri, $headers, [], $_SERVER, $body);

        $response = $this->app->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('failure', $data['status']);
        $this->assertStringContainsString('unsupported image path', $data['error']);
    }
}
