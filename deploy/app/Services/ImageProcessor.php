<?php
namespace App\Services;

use App\Core\Logger;

/**
 * Product image handling: verify, downscale, and build a thumbnail.
 *
 * Phones now produce 4–12 MB photos. Storing those untouched fills a
 * cPanel quota quickly and makes the inventory list crawl, so anything
 * uploaded is resized down before it is kept.
 *
 * GD does the work. It is present on virtually every cPanel host, but not
 * guaranteed — without it the original is stored as-is and the listing
 * falls back to scaling in the browser. Everything still functions; the
 * files are just larger.
 */
class ImageProcessor
{
    public const MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * Confirm the file really is an image, whatever the extension claims.
     *
     * @return array{ok:bool, error?:string, mime?:string, ext?:string, width?:int, height?:int}
     */
    public static function inspect(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'The uploaded file could not be read.'];
        }

        // getimagesize parses the header, so a renamed .txt is caught here.
        $info = @getimagesize($path);

        if ($info === false) {
            return ['ok' => false, 'error' => 'That file is not a valid image.'];
        }

        $mime = $info['mime'] ?? '';

        if (!isset(self::MIME_TYPES[$mime])) {
            return [
                'ok'    => false,
                'error' => 'Only JPG, PNG, GIF and WebP images can be used. That file is ' . ($mime ?: 'an unknown type') . '.',
            ];
        }

        // A "decompression bomb" — small file, enormous canvas — would
        // exhaust memory during resizing, so reject it before we allocate.
        $pixels = (int) $info[0] * (int) $info[1];

        if ($pixels > 50_000_000) {
            return ['ok' => false, 'error' => 'That image is too large to process (over 50 megapixels).'];
        }

        return [
            'ok'     => true,
            'mime'   => $mime,
            'ext'    => self::MIME_TYPES[$mime],
            'width'  => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /**
     * Write a resized copy of $source to $destination, longest edge capped
     * at $maxEdge. Images already smaller are copied unchanged.
     *
     * @return array{ok:bool, width?:int, height?:int, error?:string}
     */
    public static function resize(string $source, string $destination, int $maxEdge, int $quality = 82): array
    {
        $info = self::inspect($source);

        if (!$info['ok']) {
            return $info;
        }

        if (!self::available()) {
            return copy($source, $destination)
                ? ['ok' => true, 'width' => $info['width'], 'height' => $info['height']]
                : ['ok' => false, 'error' => 'Could not save the image.'];
        }

        [$width, $height] = [$info['width'], $info['height']];
        $longest = max($width, $height);

        // Already small enough — copying beats a needless re-encode, which
        // would only lose quality.
        if ($longest <= $maxEdge && $info['ext'] !== 'webp') {
            return copy($source, $destination)
                ? ['ok' => true, 'width' => $width, 'height' => $height]
                : ['ok' => false, 'error' => 'Could not save the image.'];
        }

        $scale     = min(1.0, $maxEdge / $longest);
        $newWidth  = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        if (!self::memoryFor($width, $height)) {
            // Not enough headroom to decode it — keep the original rather
            // than crashing the request with a fatal allocation error.
            Logger::warning('Skipped image resize: insufficient memory', [
                'width' => $width, 'height' => $height,
            ]);

            return copy($source, $destination)
                ? ['ok' => true, 'width' => $width, 'height' => $height]
                : ['ok' => false, 'error' => 'Could not save the image.'];
        }

        $src = self::load($source, $info['ext']);

        if ($src === null) {
            return ['ok' => false, 'error' => 'The image could not be opened. It may be corrupt.'];
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);

        // PNG, GIF and WebP can carry transparency, which would otherwise
        // come out black.
        if (in_array($info['ext'], ['png', 'gif', 'webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $saved = self::save($dst, $destination, $info['ext'], $quality);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$saved) {
            return ['ok' => false, 'error' => 'Could not write the resized image.'];
        }

        return ['ok' => true, 'width' => $newWidth, 'height' => $newHeight];
    }

    /**
     * Square, centre-cropped thumbnail — so a grid of products lines up
     * regardless of whether the photos are portrait or landscape.
     */
    public static function thumbnail(string $source, string $destination, int $size, int $quality = 78): bool
    {
        if (!self::available()) {
            return false;
        }

        $info = self::inspect($source);

        if (!$info['ok'] || !self::memoryFor($info['width'], $info['height'])) {
            return false;
        }

        $src = self::load($source, $info['ext']);

        if ($src === null) {
            return false;
        }

        [$width, $height] = [$info['width'], $info['height']];

        // Crop the largest centred square from the original.
        $edge = min($width, $height);
        $srcX = (int) (($width  - $edge) / 2);
        $srcY = (int) (($height - $edge) / 2);

        $dst = imagecreatetruecolor($size, $size);

        if (in_array($info['ext'], ['png', 'gif', 'webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        }

        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $size, $size, $edge, $edge);

        $saved = self::save($dst, $destination, $info['ext'], $quality);

        imagedestroy($src);
        imagedestroy($dst);

        return $saved;
    }

    // -- Internals -----------------------------------------------------

    /** @return \GdImage|null */
    private static function load(string $path, string $ext)
    {
        $image = match ($ext) {
            'jpg'  => @imagecreatefromjpeg($path),
            'png'  => @imagecreatefrompng($path),
            'gif'  => @imagecreatefromgif($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        return $image === false ? null : $image;
    }

    private static function save($image, string $path, string $ext, int $quality): bool
    {
        return match ($ext) {
            'jpg'  => @imagejpeg($image, $path, $quality),
            'png'  => @imagepng($image, $path, 6),          // 0-9, not a percentage
            'gif'  => @imagegif($image, $path),
            'webp' => function_exists('imagewebp') ? @imagewebp($image, $path, $quality) : false,
            default => false,
        };
    }

    /**
     * GD needs roughly 4 bytes per pixel for a truecolor canvas, plus room
     * for the source. Checked up front so an oversized photo degrades to
     * "stored unresized" instead of a fatal out-of-memory error.
     */
    private static function memoryFor(int $width, int $height): bool
    {
        $limit = self::memoryLimitBytes();

        if ($limit <= 0) {
            return true;   // unlimited
        }

        $needed = $width * $height * 4 * 2.2;   // source + destination + slack

        return ($needed + memory_get_usage(true)) < $limit;
    }

    private static function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $unit  = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
