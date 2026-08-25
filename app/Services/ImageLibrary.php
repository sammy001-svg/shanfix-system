<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;

/**
 * Photos attached to a record.
 *
 * Written by pulling the working routine out of InventoryController so
 * that services could have photos too. The alternative was a second copy
 * of it, and this is the code that decides whether a file a stranger
 * uploaded reaches the filesystem — a flaw in it would then have to be
 * found and fixed twice, which is how one of the two copies stays broken.
 *
 * A kind is one line in KINDS: which table, which column points at the
 * parent, where the files live, and which setting caps the count.
 */
class ImageLibrary
{
    /**
     * @var array<string,array{table:string, fk:string, folder:string, max:string}>
     */
    private const KINDS = [
        'product' => [
            'table'  => 'inventory_images',
            'fk'     => 'item_id',
            'folder' => 'products',
            'max'    => 'product_images_max',
        ],
        'service' => [
            'table'  => 'service_images',
            'fk'     => 'service_id',
            'folder' => 'services',
            'max'    => 'service_images_max',
        ],
    ];

    public static function isKind(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    /** How many more may be added before the cap is reached. */
    public static function room(string $kind, int $ownerId): int
    {
        return max(0, self::cap($kind) - self::count($kind, $ownerId));
    }

    public static function cap(string $kind): int
    {
        return max(1, Settings::int(self::KINDS[$kind]['max'], 6));
    }

    public static function count(string $kind, int $ownerId): int
    {
        $k = self::KINDS[$kind];

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM {$k['table']} WHERE {$k['fk']} = :id",
            ['id' => $ownerId],
            0
        );
    }

    /** @return array<int,array<string,mixed>> newest arrangement first */
    public static function all(string $kind, int $ownerId): array
    {
        $k = self::KINDS[$kind];

        return Database::all(
            "SELECT * FROM {$k['table']} WHERE {$k['fk']} = :id ORDER BY is_primary DESC, sort_order, id",
            ['id' => $ownerId]
        );
    }

    /**
     * Take whatever was uploaded under $field.
     *
     * A bad file is skipped with a reason rather than failing the whole
     * save: the record matters more than the picture, and making somebody
     * retype a form because one photo was the wrong format is the wrong
     * trade.
     *
     * @return array{saved:int, errors:array<int,string>}
     */
    public static function store(string $kind, int $ownerId, string $field = 'images'): array
    {
        if (!self::isKind($kind)) {
            return ['saved' => 0, 'errors' => ['Unknown image kind.']];
        }

        $k     = self::KINDS[$kind];
        $files = $_FILES[$field] ?? null;

        if (!is_array($files) || !isset($files['name'])) {
            return ['saved' => 0, 'errors' => []];
        }

        $names  = (array) $files['name'];
        $count  = count($names);
        $saved  = 0;
        $errors = [];

        $existing = self::count($kind, $ownerId);
        $max      = self::cap($kind);

        $maxEdge  = max(400, Settings::int('product_image_max_px', 1600));
        $thumbPx  = max(100, Settings::int('product_thumb_px', 400));
        $maxBytes = (int) Config::get('uploads.max_size_mb', 8) * 1024 * 1024;

        $dir = STORAGE_PATH . '/uploads/' . $k['folder'];

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['saved' => 0, 'errors' => ['Could not create the image folder on the server.']];
        }

        for ($i = 0; $i < $count; $i++) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $original = trim((string) ($names[$i] ?? 'image'));

            if ($existing + $saved >= $max) {
                $errors[] = 'Only ' . $max . ' photos are allowed — "' . str_excerpt($original, 30) . '" was skipped.';
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                $errors[] = match ($error) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                        '"' . str_excerpt($original, 30) . '" is larger than the server allows.',
                    UPLOAD_ERR_PARTIAL =>
                        '"' . str_excerpt($original, 30) . '" only uploaded partially. Try again.',
                    default =>
                        '"' . str_excerpt($original, 30) . '" could not be uploaded (error ' . $error . ').',
                };
                continue;
            }

            $tmp = (string) ($files['tmp_name'][$i] ?? '');

            if (!is_uploaded_file($tmp)) {
                $errors[] = '"' . str_excerpt($original, 30) . '" was rejected.';
                continue;
            }

            if ((int) ($files['size'][$i] ?? 0) > $maxBytes) {
                $errors[] = '"' . str_excerpt($original, 30) . '" is over the '
                          . Config::get('uploads.max_size_mb', 8) . 'MB limit.';
                continue;
            }

            // Header inspection, not the file extension — a renamed script
            // never reaches the filesystem.
            $info = ImageProcessor::inspect($tmp);

            if (!$info['ok']) {
                $errors[] = '"' . str_excerpt($original, 30) . '": ' . $info['error'];
                continue;
            }

            $base      = bin2hex(random_bytes(12));
            $fileName  = $base . '.' . $info['ext'];
            $thumbName = $base . '_thumb.' . $info['ext'];

            $result = ImageProcessor::resize($tmp, $dir . '/' . $fileName, $maxEdge);

            if (!$result['ok']) {
                $errors[] = '"' . str_excerpt($original, 30) . '": ' . ($result['error'] ?? 'could not be saved.');
                continue;
            }

            $hasThumb = ImageProcessor::thumbnail($tmp, $dir . '/' . $thumbName, $thumbPx);

            Database::insert($k['table'], [
                $k['fk']      => $ownerId,
                'file_path'   => 'uploads/' . $k['folder'] . '/' . $fileName,
                'thumb_path'  => $hasThumb ? 'uploads/' . $k['folder'] . '/' . $thumbName : null,
                'file_name'   => mb_substr($original, 0, 200),
                'file_size'   => (int) @filesize($dir . '/' . $fileName),
                'width'       => $result['width'] ?? null,
                'height'      => $result['height'] ?? null,
                // The first photo on a bare record becomes the main one.
                'is_primary'  => ($existing + $saved) === 0 ? 1 : 0,
                'sort_order'  => $existing + $saved,
                'uploaded_by' => Auth::id(),
            ]);

            $saved++;
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /** One image, only if it belongs to that owner. */
    public static function find(string $kind, int $ownerId, int $imageId): ?array
    {
        $k = self::KINDS[$kind];

        return Database::first(
            "SELECT * FROM {$k['table']} WHERE id = :img AND {$k['fk']} = :own",
            ['img' => $imageId, 'own' => $ownerId]
        );
    }

    /**
     * Remove one, and its files.
     *
     * If it was the main picture the next one takes over, so a record is
     * never left with photos but nothing chosen to represent it.
     */
    public static function delete(string $kind, int $ownerId, int $imageId): bool
    {
        $image = self::find($kind, $ownerId, $imageId);

        if (!$image) {
            return false;
        }

        $k = self::KINDS[$kind];

        foreach (['file_path', 'thumb_path'] as $col) {
            if (!empty($image[$col])) {
                @unlink(STORAGE_PATH . '/' . $image[$col]);
            }
        }

        Database::delete($k['table'], ['id' => $image['id']]);

        if ((int) $image['is_primary'] === 1) {
            $next = Database::first(
                "SELECT id FROM {$k['table']} WHERE {$k['fk']} = :id ORDER BY sort_order, id LIMIT 1",
                ['id' => $ownerId]
            );

            if ($next) {
                Database::update($k['table'], ['is_primary' => 1], ['id' => $next['id']]);
            }
        }

        return true;
    }

    /** Choose which photo stands for the record. */
    public static function makePrimary(string $kind, int $ownerId, int $imageId): bool
    {
        $image = self::find($kind, $ownerId, $imageId);

        if (!$image) {
            return false;
        }

        $k = self::KINDS[$kind];

        Database::run(
            "UPDATE {$k['table']} SET is_primary = 0 WHERE {$k['fk']} = :id",
            ['id' => $ownerId]
        );

        Database::update($k['table'], ['is_primary' => 1], ['id' => $imageId]);

        return true;
    }
}
