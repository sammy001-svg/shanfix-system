<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\ImageLibrary;

/**
 * The work the website shows.
 *
 * Three lists that answer three different questions a visitor asks:
 * what have you built, what does your printing look like, and who else
 * trusts you. They are kept in one controller because they are one job —
 * somebody sits down to write up the portfolio, not to write up the
 * projects — and one screen with three tabs is how that job is done.
 *
 * The website had none of this. Its portfolio page carried three
 * invented case studies written into the page itself, with invented
 * figures attached to them, which is the fault the testimonials had
 * before they moved here. Everything on the public page now comes from
 * these tables, and a section with nothing in it is left out rather than
 * filled in.
 *
 * A client's logo is their property and showing it says they are a
 * customer of ours. There is a field for recording who agreed to that.
 * It is never published; it is there so the question gets asked.
 */
class PortfolioController extends Controller
{
    /** The tabs, and what each one is called. */
    private const TABS = [
        'projects' => 'Websites & systems',
        'work'     => 'Printing & branding',
        'clients'  => 'Clients',
    ];

    public const KINDS = [
        'website' => 'Website',
        'system'  => 'System',
    ];

    public function index(Request $request): void
    {
        $tab = (string) $request->query('tab', 'projects');

        if (!isset(self::TABS[$tab])) {
            $tab = 'projects';
        }

        $editId = $request->int('edit');

        $this->view('portfolio/index', [
            'title'    => 'Portfolio',
            'tab'      => $tab,
            'tabs'     => self::TABS,
            'kinds'    => self::KINDS,
            'projects' => Database::all(
                'SELECT * FROM site_projects ORDER BY is_active DESC, sort_order, id'
            ),
            'work'     => Database::all(
                'SELECT * FROM site_work ORDER BY is_active DESC, sort_order, id'
            ),
            'clients'  => Database::all(
                'SELECT * FROM site_clients ORDER BY is_active DESC, sort_order, id'
            ),
            // Keyed by owner id so the lists can show what is attached
            // without a query per row.
            'images'   => [
                'project' => $this->imagesByOwner('site_project_images', 'project_id'),
                'work'    => $this->imagesByOwner('site_work_images', 'work_id'),
                'client'  => $this->imagesByOwner('site_client_images', 'client_id'),
            ],
            'editing'  => $editId > 0 ? $this->rowFor($tab, $editId) : null,
            'caps'     => [
                'project' => ImageLibrary::cap('project'),
                'work'    => ImageLibrary::cap('work'),
                'client'  => ImageLibrary::cap('client'),
            ],
        ]);
    }

    // -- Websites and systems ---------------------------------------------

    public function saveProject(Request $request): void
    {
        $id    = $request->int('id');
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            Session::error('A project needs a name.');
            Response::to('/portfolio?tab=projects' . ($id > 0 ? '&edit=' . $id : ''));
        }

        $kind = (string) $request->input('kind', 'website');

        $data = [
            'kind'         => isset(self::KINDS[$kind]) ? $kind : 'website',
            'title'        => mb_substr($title, 0, 160),
            'client_name'  => mb_substr(trim((string) $request->input('client_name', '')), 0, 160) ?: null,
            'summary'      => mb_substr(trim((string) $request->input('summary', '')), 0, 400) ?: null,
            'description'  => trim((string) $request->input('description', '')) ?: null,
            'built_with'   => $this->tidyList((string) $request->input('built_with', '')),
            'live_url'     => $this->tidyUrl((string) $request->input('live_url', '')),
            'completed_on' => $this->tidyDate((string) $request->input('completed_on', '')),
            'is_featured'  => $request->input('is_featured') ? 1 : 0,
            'is_active'    => $request->input('is_active') ? 1 : 0,
            'sort_order'   => max(0, min(9999, $request->int('sort_order'))),
        ];

        $id = $this->store('site_projects', $data, $id, 'project', $title);

        ImageLibrary::store('project', $id, 'images');

        Response::to('/portfolio?tab=projects');
    }

    // -- Printing and branding --------------------------------------------

    public function saveWork(Request $request): void
    {
        $id    = $request->int('id');
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            Session::error('A job needs a name.');
            Response::to('/portfolio?tab=work' . ($id > 0 ? '&edit=' . $id : ''));
        }

        $data = [
            'title'        => mb_substr($title, 0, 160),
            'category'     => mb_substr(trim((string) $request->input('category', '')), 0, 80) ?: null,
            'client_name'  => mb_substr(trim((string) $request->input('client_name', '')), 0, 160) ?: null,
            'description'  => mb_substr(trim((string) $request->input('description', '')), 0, 400) ?: null,
            'completed_on' => $this->tidyDate((string) $request->input('completed_on', '')),
            'is_featured'  => $request->input('is_featured') ? 1 : 0,
            'is_active'    => $request->input('is_active') ? 1 : 0,
            'sort_order'   => max(0, min(9999, $request->int('sort_order'))),
        ];

        $id = $this->store('site_work', $data, $id, 'work', $title);

        ImageLibrary::store('work', $id, 'images');

        Response::to('/portfolio?tab=work');
    }

    // -- Clients ----------------------------------------------------------

    public function saveClient(Request $request): void
    {
        $id   = $request->int('id');
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            Session::error('A client needs a name.');
            Response::to('/portfolio?tab=clients' . ($id > 0 ? '&edit=' . $id : ''));
        }

        $data = [
            'name'            => mb_substr($name, 0, 160),
            'website_url'     => $this->tidyUrl((string) $request->input('website_url', '')),
            'what_we_did'     => mb_substr(trim((string) $request->input('what_we_did', '')), 0, 255) ?: null,
            'permission_note' => mb_substr(trim((string) $request->input('permission_note', '')), 0, 255) ?: null,
            'is_featured'     => $request->input('is_featured') ? 1 : 0,
            'is_active'       => $request->input('is_active') ? 1 : 0,
            'sort_order'      => max(0, min(9999, $request->int('sort_order'))),
        ];

        $id = $this->store('site_clients', $data, $id, 'client', $name);

        // One logo. A second upload replaces the first rather than
        // stacking up behind it, because the cap would otherwise refuse
        // the new one and leave the old one showing.
        if ($this->hasUpload('images')) {
            foreach (ImageLibrary::all('client', $id) as $old) {
                ImageLibrary::delete('client', $id, (int) $old['id']);
            }
        }

        ImageLibrary::store('client', $id, 'images');

        Response::to('/portfolio?tab=clients');
    }

    // -- Showing, hiding and removing -------------------------------------

    public function toggle(Request $request): void
    {
        [$table, $tab, $label] = $this->target($request);
        $id = $request->paramInt('id');

        Database::run("UPDATE {$table} SET is_active = 1 - is_active WHERE id = :id", ['id' => $id]);

        $on = (int) Database::scalar("SELECT is_active FROM {$table} WHERE id = :id", ['id' => $id]);

        Session::success($on ? 'Showing on the website.' : 'Hidden from the website.');
        Response::to('/portfolio?tab=' . $tab);
    }

    public function destroy(Request $request): void
    {
        [$table, $tab, $label, $kind, $nameCol] = $this->target($request);
        $id   = $request->paramInt('id');
        $name = Database::scalar("SELECT {$nameCol} FROM {$table} WHERE id = :id", ['id' => $id]);

        if ($name === null) {
            throw new HttpException(404, 'That is not there.');
        }

        // The pictures go with it: the row is about to disappear and the
        // files would otherwise sit in storage with nothing pointing at
        // them. The foreign key clears the rows; this clears the disk.
        foreach (ImageLibrary::all($kind, $id) as $image) {
            ImageLibrary::delete($kind, $id, (int) $image['id']);
        }

        Database::delete($table, ['id' => $id]);

        ActivityLog::record('portfolio_delete', 'site_' . $kind, $id,
            'Removed ' . $label . ' "' . $name . '" from the website');

        Session::success('Removed.');
        Response::to('/portfolio?tab=' . $tab);
    }

    public function deleteImage(Request $request): void
    {
        [$table, $tab, $label, $kind] = $this->target($request);

        $ownerId = $request->paramInt('id');
        $imageId = $request->paramInt('image');

        ImageLibrary::delete($kind, $ownerId, $imageId);

        Session::success('Picture removed.');
        Response::to('/portfolio?tab=' . $tab . '&edit=' . $ownerId);
    }

    public function primaryImage(Request $request): void
    {
        [$table, $tab, $label, $kind] = $this->target($request);

        $ownerId = $request->paramInt('id');
        ImageLibrary::makePrimary($kind, $ownerId, $request->paramInt('image'));

        Session::success('That is the one the website leads with.');
        Response::to('/portfolio?tab=' . $tab . '&edit=' . $ownerId);
    }

    // -- Internals ---------------------------------------------------------

    /**
     * Which of the three a routed request is about.
     *
     * The type comes from the route rather than from the form, so no
     * request can name a table.
     *
     * @return array{0:string,1:string,2:string,3:string,4:string}
     */
    private function target(Request $request): array
    {
        return match ((string) $request->param('type')) {
            'work'    => ['site_work',     'work',     'the job',     'work',    'title'],
            'clients' => ['site_clients',  'clients',  'the client',  'client',  'name'],
            default   => ['site_projects', 'projects', 'the project', 'project', 'title'],
        };
    }

    private function rowFor(string $tab, int $id): ?array
    {
        $table = match ($tab) {
            'work'    => 'site_work',
            'clients' => 'site_clients',
            default   => 'site_projects',
        };

        return Database::first("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
    }

    /**
     * Insert or update, log it, and say so.
     *
     * @return int the row's id either way
     */
    private function store(string $table, array $data, int $id, string $kind, string $name): int
    {
        if ($id > 0) {
            Database::update($table, $data, ['id' => $id]);
            ActivityLog::record('portfolio_update', 'site_' . $kind, $id, 'Edited "' . $name . '"');
            Session::success('Saved.');

            return $id;
        }

        $data['created_by'] = Auth::id();
        $id = Database::insert($table, $data);

        ActivityLog::record('portfolio_create', 'site_' . $kind, $id, 'Added "' . $name . '" to the website');

        Session::success($data['is_active']
            ? 'Added. It is on the website now.'
            : 'Added, hidden. Switch it on when you are ready for it to be public.');

        return $id;
    }

    /** @return array<int,array<int,array<string,mixed>>> */
    private function imagesByOwner(string $table, string $fk): array
    {
        $out = [];

        foreach (Database::all("SELECT * FROM {$table} ORDER BY is_primary DESC, sort_order, id") as $row) {
            $out[(int) $row[$fk]][] = $row;
        }

        return $out;
    }

    private function hasUpload(string $field): bool
    {
        return !empty($_FILES[$field]['name'][0]) || !empty($_FILES[$field]['name']);
    }

    /** "php, mysql ,  bootstrap" => "PHP, MySQL, Bootstrap"-ish, tidied. */
    private function tidyList(string $raw): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn($p) => $p !== '');

        return $parts === [] ? null : mb_substr(implode(', ', $parts), 0, 255);
    }

    /**
     * A link that opens, or nothing.
     *
     * Somebody typing "shanfixtechnology.com" means https. A link without
     * a scheme is published as a relative one and goes to a page on our
     * own site that does not exist.
     */
    private function tidyUrl(string $raw): ?string
    {
        $url = trim($raw);

        if ($url === '') {
            return null;
        }

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? mb_substr($url, 0, 255) : null;
    }

    private function tidyDate(string $raw): ?string
    {
        $date = trim($raw);

        return $date !== '' && strtotime($date) !== false ? date('Y-m-d', strtotime($date)) : null;
    }
}
