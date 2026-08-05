<?php
namespace App\Core;

/**
 * Shared controller helpers: rendering, pagination, uploads.
 */
abstract class Controller
{
    protected function view(string $template, array $data = [], ?string $layout = 'app'): void
    {
        // Surface flashed validation state to every view without repetition.
        $data['errors'] = $data['errors'] ?? Session::pullErrors();
        View::render($template, $data, $layout);
        Session::clearOld();
    }

    protected function authorize(string $permission): void
    {
        Auth::authorize($permission);
    }

    /**
     * Build a LIMIT/OFFSET pager.
     *
     * @return array{page:int, perPage:int, offset:int, total:int, pages:int}
     */
    protected function paginate(int $total, int $perPage = 25): array
    {
        $perPage = max(1, min($perPage, 200));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min((int) ($_GET['page'] ?? 1), $pages));

        return [
            'page'    => $page,
            'perPage' => $perPage,
            'offset'  => ($page - 1) * $perPage,
            'total'   => $total,
            'pages'   => $pages,
        ];
    }

    /**
     * Validate and store an uploaded file under storage/uploads/$folder.
     * Returns the path relative to storage/, or null when nothing was sent.
     */
    protected function storeUpload(?array $file, string $folder): ?string
    {
        if ($file === null) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'The file could not be uploaded. Please try again.');
        }

        $maxBytes = (int) Config::get('uploads.max_size_mb', 8) * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            throw new HttpException(422, 'File is too large. Maximum size is ' . Config::get('uploads.max_size_mb', 8) . 'MB.');
        }

        $ext     = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $allowed = Config::get('uploads.allowed_types', []);

        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new HttpException(422, 'That file type is not allowed. Permitted: ' . implode(', ', $allowed));
        }

        // Trust the sniffed type over the client-supplied one for images/PDFs.
        if (!$this->mimeLooksSafe($file['tmp_name'] ?? '', $ext)) {
            throw new HttpException(422, 'The file contents do not match its extension.');
        }

        $dir = STORAGE_PATH . '/uploads/' . trim($folder, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create upload directory.');
        }

        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            // Fall back for CLI/testing where move_uploaded_file refuses.
            if (!@rename($file['tmp_name'], $dest)) {
                throw new \RuntimeException('Failed to save the uploaded file.');
            }
        }

        return 'uploads/' . trim($folder, '/') . '/' . $name;
    }

    private function mimeLooksSafe(string $tmpPath, string $ext): bool
    {
        if (!is_file($tmpPath) || !function_exists('finfo_open')) {
            return true;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        $expected = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf'  => ['application/pdf'],
        ];

        // Office/zip types vary too much between servers to check strictly.
        if (!isset($expected[$ext])) {
            return true;
        }

        return in_array($mime, $expected[$ext], true);
    }

    protected function deleteUpload(?string $relativePath): void
    {
        if (!$relativePath) {
            return;
        }

        // Refuse anything trying to escape the storage directory.
        $full = realpath(STORAGE_PATH . '/' . $relativePath);
        $root = realpath(STORAGE_PATH);

        if ($full && $root && str_starts_with($full, $root) && is_file($full)) {
            @unlink($full);
        }
    }
}
