<?php

namespace App\Services;

use Psr\Http\Message\UploadedFileInterface;
use App\Services\ImageResizeService;

class UploadService
{
    /**
     * Handles validation and moving of uploaded files.
     *
     * @param UploadedFileInterface $file
     * @return string
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function handleUpload(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file->getError());
        }

        $clientFilename = (string)$file->getClientFilename();
        $extension = strtolower(pathinfo($clientFilename, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        if (!in_array($extension, $allowedExtensions)) {
            throw new \InvalidArgumentException('Unsupported file type: ' . $extension);
        }

        $filePath = null;
        if (method_exists($file, 'getFilePath')) {
            $filePath = $file->getFilePath();
        }
        if (!$filePath) {
            $filePath = $file->getStream()->getMetadata('uri');
        }

        if (!is_string($filePath) || !is_file($filePath)) {
            throw new \RuntimeException('Temporary file not found');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        if ($mimeType === false) {
            throw new \RuntimeException('Failed to determine MIME type of the uploaded file');
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!in_array($mimeType, $allowedMimeTypes)) {
            throw new \InvalidArgumentException('Unsupported MIME type: ' . $mimeType);
        }

        $basename = bin2hex(random_bytes(8));
        $filename = sprintf('%s.%0.8s', $basename, $extension);

        $isImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp']);
        $downscaledTempPath = null;
        if ($isImage) {
            $downscaledTempPath = sys_get_temp_dir() . '/' . uniqid('downscaled_', true) . '.webp';
            ImageResizeService::createDownscaledWebp($filePath, $downscaledTempPath);
            if (!file_exists($downscaledTempPath) || filesize($downscaledTempPath) === 0) {
                if (file_exists($downscaledTempPath)) {
                    unlink($downscaledTempPath);
                }
                $downscaledTempPath = null;
            }
        }

        if (S3Service::isConfigured()) {
            $content = file_get_contents($filePath);
            if ($content === false) {
                if ($downscaledTempPath && file_exists($downscaledTempPath)) {
                    unlink($downscaledTempPath);
                }
                throw new \RuntimeException('Failed to read temporary file');
            }
            S3Service::put('uploads/' . $filename, $content, $mimeType);

            if ($downscaledTempPath) {
                $downscaledContent = file_get_contents($downscaledTempPath);
                if ($downscaledContent !== false) {
                    S3Service::put('uploads/' . $filename . '.downscaled.webp', $downscaledContent, 'image/webp');
                }
                unlink($downscaledTempPath);
            }
        } else {
            $directory = (string)getenv('UPLOADS_DIR');
            if ($directory === '') {
                $directory = dirname(__DIR__, 2) . '/public/uploads';
            }

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $file->moveTo($directory . DIRECTORY_SEPARATOR . $filename);

            if ($downscaledTempPath) {
                rename($downscaledTempPath, $directory . DIRECTORY_SEPARATOR . $filename . '.downscaled.webp');
            }
        }

        return '/api/uploads/' . $filename;
    }
}
