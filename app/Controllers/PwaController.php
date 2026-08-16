<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Settings;

/**
 * Everything the installable app needs: the manifest, the service worker
 * and the launcher icons.
 *
 * All three are served by PHP rather than sitting as files under assets/.
 * Two reasons, both learned the hard way:
 *
 *  - A service worker may only control the paths below its own URL. Parked
 *    at /assets/sw.js it could only ever see /assets/*, which is useless.
 *    It has to answer from the root.
 *  - The flat cPanel build moves the document root, so a file that works in
 *    one layout is a 403 in the other. A route works in both.
 *
 * The icons are drawn from the uploaded company logo, so an installed app
 * carries the operator's own branding with nothing extra to upload.
 */
class PwaController extends Controller
{
    /** Bump to retire every client's cached shell after a deploy. */
    private const CACHE_VERSION = 'v1';

    private const ICON_SIZES = [192, 512];

    public function manifest(Request $request): void
    {
        $this->json($this->manifestData(), 'application/manifest+json');
    }

    /**
     * The manifest as data, so it can be inspected without emitting it.
     *
     * @return array<string,mixed>
     */
    public function manifestData(): array
    {
        $name  = (string) Settings::get('company_name', 'Shanfix Technology');
        $short = mb_substr(explode(' ', trim($name))[0] ?: 'Shanfix', 0, 12);

        $icons = [];
        foreach (self::ICON_SIZES as $size) {
            $icons[] = [
                'src'     => url('icon-' . $size . '.png'),
                'sizes'   => $size . 'x' . $size,
                'type'    => 'image/png',
                // "any maskable" lets Android crop it to the launcher shape
                // without the logo losing its corners.
                'purpose' => 'any maskable',
            ];
        }

        return [
            'name'             => $name,
            'short_name'       => $short,
            'description'      => 'Quotations, invoices, production and deliveries for ' . $name . '.',
            'start_url'        => url('/dashboard'),
            'scope'            => url('/'),
            'display'          => 'standalone',
            'orientation'      => 'any',
            'background_color' => '#0C2B4A',
            'theme_color'      => '#0C2B4A',
            'lang'             => 'en-KE',
            'dir'              => 'ltr',
            'icons'            => $icons,
            'shortcuts'        => [
                [
                    'name'  => 'Production board',
                    'url'   => url('/jobs'),
                    'icons' => [['src' => url('icon-192.png'), 'sizes' => '192x192']],
                ],
                [
                    'name'  => 'Invoices',
                    'url'   => url('/invoices'),
                    'icons' => [['src' => url('icon-192.png'), 'sizes' => '192x192']],
                ],
            ],
        ];
    }

    /**
     * The service worker itself.
     *
     * Served with Service-Worker-Allowed so a worker delivered from a
     * sub-path may still control the whole site.
     */
    public function serviceWorker(Request $request): void
    {
        $base    = rtrim(base_path(), '/');
        $shell   = [
            url('/offline'),
            asset('css/app.css'),
            asset('js/app.js'),
            asset('js/offline.js'),
            url('/icon-192.png'),
        ];

        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: ' . ($base === '' ? '/' : $base . '/'));
        header('Cache-Control: no-cache');

        // The worker is generated so it can be told the deployment's base
        // path and cache version; everything else is static.
        echo "const VERSION = " . json_encode(self::CACHE_VERSION) . ";\n";
        echo "const BASE = " . json_encode($base) . ";\n";
        echo "const OFFLINE_URL = " . json_encode(url('/offline')) . ";\n";
        echo "const SHELL = " . json_encode(array_values($shell)) . ";\n";
        echo file_get_contents(APP_PATH . '/Views/pwa/service-worker.js');
        exit;
    }

    /**
     * A launcher icon at the requested size.
     *
     * Uses the uploaded logo when there is one, centred on the brand navy
     * with a safe margin so a maskable crop cannot clip it. Falls back to
     * the SF monogram.
     */
    public function icon(Request $request): void
    {
        $size = (int) $request->param('size');

        if (!in_array($size, self::ICON_SIZES, true)) {
            throw new HttpException(404, 'No icon at that size.');
        }

        if (!function_exists('imagecreatetruecolor')) {
            throw new HttpException(404, 'Image support is not available on this server.');
        }

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        echo $this->renderIcon($size);
        exit;
    }

    /** The icon as PNG bytes, so it can be checked without being emitted. */
    public function renderIcon(int $size): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, true);

        // Brand navy, matching the manifest background so the splash screen
        // and the icon are the same colour.
        $navy = imagecolorallocate($canvas, 0x0C, 0x2B, 0x4A);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $navy);

        $logo = $this->logoResource();

        if ($logo !== null) {
            // 20% margin all round keeps the mark inside the maskable safe
            // zone, which Android crops to a circle on some launchers.
            $box = (int) round($size * 0.60);
            $lw  = imagesx($logo);
            $lh  = imagesy($logo);
            $ratio = min($box / $lw, $box / $lh);
            $dw  = max(1, (int) round($lw * $ratio));
            $dh  = max(1, (int) round($lh * $ratio));

            imagecopyresampled(
                $canvas, $logo,
                (int) (($size - $dw) / 2), (int) (($size - $dh) / 2),
                0, 0, $dw, $dh, $lw, $lh
            );

            imagedestroy($logo);
        } else {
            $this->drawMonogram($canvas, $size);
        }

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return $png;
    }

    /** The offline fallback page, shown when a navigation cannot be served. */
    public function offline(Request $request): void
    {
        $this->view('errors/offline', [
            'title' => 'You are offline',
        ], 'blank');
    }

    /**
     * URLs worth having cached before the signal goes.
     *
     * The jobs this user is working on and the clients they touched
     * recently — the pages someone actually opens on a workshop floor.
     */
    public function precache(Request $request): void
    {
        $urls = [url('/dashboard'), url('/jobs')];

        if (Auth::check() && Auth::can('jobs.view')) {
            $jobs = Database::all(
                "SELECT id FROM jobs
                  WHERE stage NOT IN ('delivered','cancelled')
                    AND (assigned_to = :uid OR assigned_to IS NULL)
               ORDER BY COALESCE(due_date, created_at)
                  LIMIT 25",
                ['uid' => Auth::id()]
            );

            foreach ($jobs as $job) {
                $urls[] = url('/jobs/' . $job['id']);
            }
        }

        if (Auth::check() && Auth::can('clients.view')) {
            $clients = Database::all(
                'SELECT id FROM clients WHERE status = :s ORDER BY updated_at DESC LIMIT 15',
                ['s' => 'active']
            );

            foreach ($clients as $client) {
                $urls[] = url('/clients/' . $client['id']);
            }
        }

        $this->json(['urls' => array_values(array_unique($urls))]);
    }

    // -- helpers -------------------------------------------------------

    private function json(array $payload, string $contentType = 'application/json'): void
    {
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Cache-Control: no-cache');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /** @return \GdImage|null */
    private function logoResource()
    {
        $relative = (string) Settings::get('company_logo', '');

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $full = realpath(STORAGE_PATH . '/' . $relative);
        $root = realpath(STORAGE_PATH . '/uploads');

        if (!$full || !$root || !str_starts_with($full, $root) || !is_file($full)) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($full));

        return $image === false ? null : $image;
    }

    private function drawMonogram(\GdImage $canvas, int $size): void
    {
        $green = imagecolorallocate($canvas, 0x14, 0x87, 0x4E);
        $white = imagecolorallocate($canvas, 0xFF, 0xFF, 0xFF);

        // A rounded-ish plate behind the letters, drawn as a plain rectangle
        // because GD has no rounded primitive and the launcher masks it anyway.
        $pad = (int) round($size * 0.22);
        imagefilledrectangle($canvas, $pad, $pad, $size - $pad, $size - $pad, $green);

        // Built-in font 5 is small, so scale it up by drawing to a little
        // canvas and resampling — no font file needed on the server.
        $tile = imagecreatetruecolor(24, 12);
        $bg   = imagecolorallocate($tile, 0x14, 0x87, 0x4E);
        imagefilledrectangle($tile, 0, 0, 24, 12, $bg);
        imagestring($tile, 5, 3, 0, 'SF', imagecolorallocate($tile, 0xFF, 0xFF, 0xFF));

        $tw = (int) round($size * 0.42);
        $th = (int) round($tw / 2);
        imagecopyresampled(
            $canvas, $tile,
            (int) (($size - $tw) / 2), (int) (($size - $th) / 2),
            0, 0, $tw, $th, 24, 12
        );

        imagedestroy($tile);
        unset($white);
    }
}
