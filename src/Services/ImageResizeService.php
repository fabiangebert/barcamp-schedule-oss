<?php

namespace App\Services;

class ImageResizeService
{
    public static function createDownscaledWebp(string $sourcePath, string $targetPath): void
    {
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return;
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $type = $imageInfo[2];

        $maxSize = 2048;

        $sourceImage = null;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                if ($sourceImage && function_exists('exif_read_data')) {
                    $exif = @exif_read_data($sourcePath);
                    if ($exif && isset($exif['Orientation'])) {
                        switch ($exif['Orientation']) {
                            case 3:
                                $sourceImage = imagerotate($sourceImage, 180, 0);
                                break;
                            case 6:
                                $sourceImage = imagerotate($sourceImage, -90, 0);
                                $temp = $width;
                                $width = $height;
                                $height = $temp;
                                break;
                            case 8:
                                $sourceImage = imagerotate($sourceImage, 90, 0);
                                $temp = $width;
                                $width = $height;
                                $height = $temp;
                                break;
                        }
                    }
                }
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $sourceImage = imagecreatefromwebp($sourcePath);
                break;
        }

        if (!$sourceImage) {
            return;
        }

        $newWidth = $width;
        $newHeight = $height;
        if ($width > $maxSize || $height > $maxSize) {
            if ($width > $height) {
                $newWidth = $maxSize;
                $newHeight = (int)round($height * ($maxSize / $width));
            } else {
                $newHeight = $maxSize;
                $newWidth = (int)round($width * ($maxSize / $height));
            }
        }

        $targetImage = imagecreatetruecolor($newWidth, $newHeight);

        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF || $type == IMAGETYPE_WEBP) {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
        }

        imagecopyresampled($targetImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $tempPath = $targetPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (imagewebp($targetImage, $tempPath, 85)) {
            $tempInfo = @getimagesize($tempPath);
            if ($tempInfo && $tempInfo[2] === IMAGETYPE_WEBP && filesize($tempPath) > 0) {
                rename($tempPath, $targetPath);
            } else {
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
            }
        }
    }
}
