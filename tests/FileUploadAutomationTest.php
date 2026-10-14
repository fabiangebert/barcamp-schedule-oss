<?php

namespace Tests;

use App\App;
use App\Database;
use App\Schedule;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

class FileUploadAutomationTest extends BaseTestCase
{
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::create();
    }

    /**
     * Automates Manual Testing Plan 5: Documentation & File Uploads
     */
    public function testFileUploadsAndValidation()
    {
        $scheduleId = 'upload-test-schedule';
        $conn = Database::getConnection();
        $conn->insert('schedules', [
            'hashed_id' => $scheduleId,
            'name' => 'Upload Test',
            'data' => json_encode(['rooms' => [], 'timeslots' => [], 'sessions' => []]),
        ]);

        $jwt = \App\Auth::generateJWT('user@example.com', 'user', 'Test User');

        // 1. Setup a dummy file to upload (valid PDF content)
        $tempFile = tempnam(sys_get_temp_dir(), 'test_upload');
        file_put_contents($tempFile, "%PDF-1.4\nfake image content");

        // 2. Test successful upload (PDF)
        $uploadedFile = new UploadedFile(
            $tempFile,
            'test.pdf',
            'application/pdf',
            filesize($tempFile)
        );

        $requestFactory = new RequestFactory();
        $request = $requestFactory->createRequest('POST', "/api/schedules/$scheduleId/upload")
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withHeader('Authorization', "Bearer $jwt");

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string)$response->getBody());

        $body = json_decode((string)$response->getBody(), true);
        $this->assertEquals('success', $body['status']);
        $this->assertStringContainsString('/api/uploads/', $body['path']);
        $this->assertStringEndsWith('.pdf', $body['path']);

        // Verify file exists in temp uploads dir
        $filename = basename($body['path']);
        $this->assertFileExists($this->tempUploadsDir . DIRECTORY_SEPARATOR . $filename);

        // 3. Test unsupported file type (Manual Test 5, Step 5)
        $txtFile = tempnam(sys_get_temp_dir(), 'test_txt');
        file_put_contents($txtFile, 'plain text');
        $unsupportedFile = new UploadedFile(
            $txtFile,
            'test.txt',
            'text/plain',
            filesize($txtFile)
        );

        $request2 = $requestFactory->createRequest('POST', "/api/schedules/$scheduleId/upload")
            ->withUploadedFiles(['file' => $unsupportedFile])
            ->withHeader('Authorization', "Bearer $jwt");

        $response2 = $this->app->handle($request2);
        $this->assertEquals(400, $response2->getStatusCode());
        $body2 = json_decode((string)$response2->getBody(), true);
        $this->assertEquals('failure', $body2['status']);
        $this->assertStringContainsString('Unsupported file type', $body2['error']);

        // Cleanup
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        if (file_exists($txtFile)) {
            unlink($txtFile);
        }
    }
}
