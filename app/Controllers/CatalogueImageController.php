<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\ClientAuth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\PartnerAuth;
use App\Core\Request;
use App\Services\ImageLibrary;

/**
 * Photographs of what we sell.
 *
 * /files is behind the staff guard, which is right for receipts and
 * artwork and wrong for these: it meant a client browsing the catalogue
 * and a partner browsing what they resell both saw nothing at all, while
 * the pictures sat in the database.
 *
 * There are two ways in, and the difference is only who may ask:
 *
 *   catalogue()  signed in as any of the three. What the portals use.
 *   photo()      open to anyone. What the public website uses, because
 *                a product photograph on a marketing page is marketing
 *                material and a visitor has no session to offer.
 *
 * Neither can name a file. Both take an image row's own id, look it up in
 * one of the two catalogue image tables, and refuse unless the thing it
 * belongs to is still active. There is no path from either to the rest of
 * storage — which is what makes the open one safe, rather than the
 * session the other one asks for.
 */
class CatalogueImageController extends Controller
{
    /** For the portals: signed in as staff, a client or a partner. */
    public function catalogue(Request $request): void
    {
        if (!Auth::check() && !ClientAuth::check() && !PartnerAuth::check()) {
            throw new HttpException(404, 'Not found.');
        }

        $this->send($request, false);
    }

    /**
     * For the website: no session, because visitors do not have one.
     *
     * Cached publicly rather than privately — this is the same picture
     * for everybody, and a shared cache in front of the site should be
     * allowed to keep it.
     */
    public function photo(Request $request): void
    {
        $this->send($request, true);
    }

    private function send(Request $request, bool $public): void
    {
        $image = ImageLibrary::catalogueImage(
            (string) $request->param('kind'),
            $request->paramInt('id')
        );

        if (!$image) {
            throw new HttpException(404, 'Not found.');
        }

        // A card wants the thumbnail; a lightbox wants the picture.
        $wantThumb = $request->query('size') === 'thumb' && !empty($image['thumb_path']);
        $relative  = (string) ($wantThumb ? $image['thumb_path'] : $image['file_path']);

        $full = realpath(STORAGE_PATH . '/' . $relative);
        $root = realpath(STORAGE_PATH . '/uploads');

        if (!$full || !$root || !str_starts_with($full, $root) || !is_file($full)) {
            throw new HttpException(404, 'Not found.');
        }

        $mime = 'image/jpeg';

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $full) ?: $mime;
            finfo_close($finfo);
        }

        // Only ever a picture, whatever ended up on disk under that row.
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new HttpException(404, 'Not found.');
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($public ? 'public' : 'private') . ', max-age=86400');
        readfile($full);
        exit;
    }
}
