<?php

namespace App\Routes;

use App\Services\ImageResizeService;
use App\Services\S3Service;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

class DownloadRoutes
{
    /**
     * @param RouteCollectorProxy $group
     * @return void
     */
    public static function register(RouteCollectorProxy $group): void
    {
        // This route is explicitly public to support standard <img> tags, which don't
        // easily support custom headers like 'Authorization'.
        // Documentation and its associated images are intended to be viewable by anyone
        // with a share link (guest access).
        $group->get('/uploads/{filename}', function (Request $request, Response $response, $args) {
            $filename = basename($args['filename']);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
            if (!in_array($extension, $allowedExtensions)) {
                return $response->withStatus(404);
            }

            if (S3Service::isConfigured()) {
                $content = null;
                $contentType = null;
                $imageExtensions = ['png', 'jpg', 'jpeg', 'webp'];

                if (in_array($extension, $imageExtensions)) {
                    try {
                        $content = S3Service::get('uploads/' . $filename . '.downscaled.webp');
                        $contentType = 'image/webp';
                    } catch (\Exception $e) {
                        // Downscaled not found, fallback to original
                    }
                }

                if ($content === null) {
                    try {
                        $content = S3Service::get('uploads/' . $filename);
                    } catch (\Exception $e) {
                        return $response->withStatus(404);
                    }
                }

                if ($content === null) {
                    return $response->withStatus(404);
                }

                if ($contentType === null) {
                    $mimeTypes = [
                        'png' => 'image/png',
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'webp' => 'image/webp',
                        'gif' => 'image/gif',
                        'pdf' => 'application/pdf'
                    ];
                    $contentType = $mimeTypes[$extension];
                }

                $response->getBody()->write($content);
                return $response->withHeader('Content-Type', $contentType);
            }

            $directory = getenv('UPLOADS_DIR') ?: (__DIR__ . '/../../public/uploads');
            $originalFilePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

            if (!file_exists($originalFilePath)) {
                return $response->withStatus(404);
            }

            $imageExtensions = ['png', 'jpg', 'jpeg', 'webp'];

            if (in_array($extension, $imageExtensions)) {
                $downscaledFilePath = $originalFilePath . '.downscaled.webp';
                if (file_exists($downscaledFilePath) && filesize($downscaledFilePath) > 0) {
                    $content = file_get_contents($downscaledFilePath);
                    $response->getBody()->write($content === false ? '' : $content);
                    return $response->withHeader('Content-Type', 'image/webp');
                }
            }

            $mimeTypes = [
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf'
            ];
            $contentType = $mimeTypes[$extension];
            $content = file_get_contents($originalFilePath);
            $response->getBody()->write($content === false ? '' : $content);
            return $response->withHeader('Content-Type', $contentType);
        });
    }
}
