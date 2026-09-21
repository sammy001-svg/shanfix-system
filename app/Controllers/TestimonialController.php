<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Client testimonials shown on the website.
 *
 * The website used to show quotes nobody had said — placeholder text in
 * its own database and fallbacks written into the page code. These are
 * the real ones, added by staff, and the website shows only what is here.
 *
 * Only put a quote here with the client's permission. It is published
 * under their name on a public page.
 */
class TestimonialController extends Controller
{
    public function index(Request $request): void
    {
        $editId = (int) $request->query('edit', 0);

        $this->view('testimonials/index', [
            'title'        => 'Testimonials',
            'testimonials' => Database::all(
                'SELECT * FROM site_testimonials ORDER BY is_active DESC, sort_order, id'
            ),
            'editing'      => $editId > 0
                ? Database::first('SELECT * FROM site_testimonials WHERE id = :id', ['id' => $editId])
                : null,
        ]);
    }

    /** Add one, or save changes to one. */
    public function save(Request $request): void
    {
        $id     = $request->int('id');
        $quote  = trim((string) $request->input('quote', ''));
        $author = trim((string) $request->input('author', ''));

        if ($quote === '' || $author === '') {
            Session::error('A testimonial needs the quote and the name of who said it.');
            Response::to('/testimonials' . ($id > 0 ? '?edit=' . $id : ''));
        }

        $data = [
            'quote'      => mb_substr($quote, 0, 1200),
            'author'     => mb_substr($author, 0, 120),
            'role'       => mb_substr(trim((string) $request->input('role', '')), 0, 120) ?: null,
            'company'    => mb_substr(trim((string) $request->input('company', '')), 0, 160) ?: null,
            'rating'     => max(1, min(5, $request->int('rating') ?: 5)),
            'sort_order' => max(0, min(9999, $request->int('sort_order'))),
            'is_active'  => $request->input('is_active') ? 1 : 0,
        ];

        if ($id > 0) {
            Database::update('site_testimonials', $data, ['id' => $id]);
            ActivityLog::record('testimonial_update', 'site_testimonial', $id, 'Edited the testimonial from ' . $author);
            Session::success('Saved.');
        } else {
            $data['created_by'] = Auth::id();
            $id = Database::insert('site_testimonials', $data);
            ActivityLog::record('testimonial_create', 'site_testimonial', $id, 'Added a testimonial from ' . $author);
            Session::success($data['is_active']
                ? 'Added. It is on the website now.'
                : 'Added, hidden. Switch it on when the client has agreed to it being published.');
        }

        Response::to('/testimonials');
    }

    /** Show or hide without editing. */
    public function toggle(Request $request): void
    {
        $id = $request->paramInt('id');

        Database::run(
            'UPDATE site_testimonials SET is_active = 1 - is_active WHERE id = :id',
            ['id' => $id]
        );

        $on = (int) Database::scalar('SELECT is_active FROM site_testimonials WHERE id = :id', ['id' => $id]);
        Session::success($on ? 'Showing on the website.' : 'Hidden from the website.');

        Response::to('/testimonials');
    }

    public function delete(Request $request): void
    {
        $id  = $request->paramInt('id');
        $who = Database::scalar('SELECT author FROM site_testimonials WHERE id = :id', ['id' => $id]);

        Database::delete('site_testimonials', ['id' => $id]);

        if ($who !== null) {
            ActivityLog::record('testimonial_delete', 'site_testimonial', $id, 'Removed the testimonial from ' . $who);
        }

        Session::success('Removed.');
        Response::to('/testimonials');
    }
}
