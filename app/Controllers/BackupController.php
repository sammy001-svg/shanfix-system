<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\Backup;

/**
 * The backups page.
 *
 * Taking a copy is scheduled and needs nobody, but three things here do
 * need a person: taking one right now before a risky change, checking a
 * copy is readable, and getting it off the server. The last one matters
 * most — a backup sitting on the same disk as the thing it is protecting
 * is not really a backup, so downloading is the primary action on the
 * page rather than something buried in a menu.
 */
class BackupController extends Controller
{
    public function index(Request $request): void
    {
        $this->authorize('settings.manage');

        $backups = Backup::all();

        $this->view('settings/backups', [
            'title'    => 'Backups',
            'tab'      => 'backups',
            'backups'  => $backups,
            'latest'   => $backups[0] ?? null,
            'warnDays' => max(1, Settings::int('backup_warn_days', 3)),
            'settings' => Settings::company(),
        ]);
    }

    /** Take one now. */
    public function create(Request $request): void
    {
        $this->authorize('settings.manage');

        // Copying the whole database is not a request that finishes in the
        // usual second or two, and the host's default limit will cut it off
        // partway on a large database, leaving a file that looks fine until
        // somebody needs it.
        @set_time_limit(600);

        $result = Backup::run(Settings::bool('backup_uploads', true));

        if (!$result['ok']) {
            Session::error('The backup failed: ' . $result['error']);
            Response::to('/settings/backups');
        }

        ActivityLog::record(
            'backup_created',
            'backup',
            null,
            sprintf('Backup %s — %d tables, %s rows', $result['name'], $result['tables'], number_format($result['rows']))
        );

        Session::success(sprintf(
            'Backup taken: %d tables, %s rows, %s. Download it and keep a copy somewhere other than this server.',
            $result['tables'],
            number_format($result['rows']),
            human_bytes($result['bytes'])
        ));

        Response::to('/settings/backups');
    }

    /**
     * Read a backup back and report whether it is intact.
     *
     * An untested backup is a guess, and the day you find out is the worst
     * possible day to find out.
     */
    public function verify(Request $request): void
    {
        $this->authorize('settings.manage');

        $name   = $this->safeName($request->param('name'));
        $result = Backup::verify($name);

        if (!$result['ok']) {
            Session::error('That backup is not usable: ' . ($result['error'] ?? 'unknown problem.'));
            Response::to('/settings/backups');
        }

        Session::success(sprintf(
            'Readable and complete: %d tables and %s rows of data.',
            $result['tables'],
            number_format($result['inserts'])
        ));

        Response::to('/settings/backups');
    }

    /** Send the file to the browser. */
    public function download(Request $request): void
    {
        $this->authorize('settings.manage');

        $name   = $this->safeName($request->param('name'));
        $backup = Backup::find($name);
        $what   = (string) $request->query('part', 'sql');

        if (!$backup) {
            Session::error('That backup no longer exists.');
            Response::to('/settings/backups');
        }

        $path = $what === 'uploads' ? $backup['uploads'] : $backup['sql'];

        if (!$path || !is_file($path)) {
            Session::error('That file is not part of this backup.');
            Response::to('/settings/backups');
        }

        ActivityLog::record('backup_downloaded', 'backup', null, 'Downloaded ' . basename($path));

        // Anything already buffered would be prepended to the file and
        // corrupt it.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        readfile($path);
        exit;
    }

    public function delete(Request $request): void
    {
        $this->authorize('settings.manage');

        $name = $this->safeName($request->param('name'));

        // Deleting the only copy leaves the business with nothing, which is
        // never what somebody tidying up a list intends.
        if (count(Backup::all()) <= 1) {
            Session::error('That is the only backup there is. Take a new one before deleting it.');
            Response::to('/settings/backups');
        }

        if (!Backup::delete($name)) {
            Session::error('That backup no longer exists.');
            Response::to('/settings/backups');
        }

        ActivityLog::record('backup_deleted', 'backup', null, 'Deleted backup ' . $name);
        Session::success('Backup deleted.');
        Response::to('/settings/backups');
    }

    /** Save the schedule. */
    public function save(Request $request): void
    {
        $this->authorize('settings.manage');

        Settings::setMany([
            'backup_enabled'     => $request->bool('backup_enabled') ? '1' : '0',
            'backup_uploads'     => $request->bool('backup_uploads') ? '1' : '0',
            'backup_every_hours' => (string) max(1, min(168, $request->int('backup_every_hours', 24))),
            'backup_keep'        => (string) max(1, min(60, $request->int('backup_keep', 7))),
            'backup_warn_days'   => (string) max(1, min(90, $request->int('backup_warn_days', 3))),
        ]);

        Settings::flush();

        ActivityLog::record('settings_updated', 'settings', null, 'Updated the backup schedule');
        Session::success('Backup settings saved.');
        Response::to('/settings/backups');
    }

    /**
     * A backup name, with anything that could walk out of the directory
     * removed. The name reaches the filesystem, so it is rebuilt from
     * characters we allow rather than checked for characters we do not.
     */
    private function safeName(?string $raw): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $raw);
    }
}
