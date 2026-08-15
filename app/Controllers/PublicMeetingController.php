<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\Meetings;

/**
 * The meeting room as an outside client sees it.
 *
 * No account, no password — the 48-character token in the link is the
 * credential, the same arrangement used for shared invoices. A guest gives
 * their name on the way in so the notes and the attendance list say who
 * was there, and lands in the same room the staff are in.
 */
class PublicMeetingController extends Controller
{
    /** The name a guest typed, kept for the length of their visit. */
    private const NAME_KEY = 'guest_name_';

    /**
     * The door: asks who they are, then lets them through.
     *
     * Deliberately a separate step rather than dropping them straight in.
     * A meeting where half the squares say "Guest" is worse than useless
     * when the minutes are read back a month later.
     */
    public function lobby(Request $request): void
    {
        $meeting = $this->findOrFail((string) $request->param('token'));

        if ($this->nameFor($meeting)) {
            Response::to('/join/' . $meeting['public_token'] . '/room');
        }

        $this->view('meetings/lobby', [
            'title'   => $meeting['title'],
            'meeting' => $meeting,
            'company' => Settings::company(),
            'starts'  => $meeting['scheduled_at'],
        ], 'public');
    }

    public function enter(Request $request): void
    {
        $meeting = $this->findOrFail((string) $request->param('token'));

        $name = trim((string) $request->input('name', ''));

        if (mb_strlen($name) < 2) {
            Session::error('Please give your name so everyone knows who is in the room.');
            Response::to('/join/' . $meeting['public_token']);
        }

        Session::put(self::NAME_KEY . $meeting['id'], mb_substr($name, 0, 160));

        // Tie them to their invitation where we can, so the attendance list
        // reflects the people actually invited rather than a list of names.
        $email = trim((string) $request->input('email', ''));

        if ($email !== '') {
            $row = Database::first(
                'SELECT id FROM meeting_participants WHERE meeting_id = :m AND email = :e LIMIT 1',
                ['m' => $meeting['id'], 'e' => $email]
            );

            if ($row) {
                Meetings::markJoined((int) $meeting['id'], null, (int) $row['id']);
                Session::put(self::NAME_KEY . $meeting['id'] . '_pid', (int) $row['id']);
            }
        }

        Response::to('/join/' . $meeting['public_token'] . '/room');
    }

    public function room(Request $request): void
    {
        $meeting = $this->findOrFail((string) $request->param('token'));
        $name    = $this->nameFor($meeting);

        if (!$name) {
            Response::to('/join/' . $meeting['public_token']);
        }

        $this->view('meetings/room', [
            'title'    => $meeting['title'],
            'meeting'  => $meeting,
            'notes'    => Meetings::notes((int) $meeting['id']),
            'meName'   => $name,
            'isHost'   => false,
            'canWrite' => true,
            'ice'      => Meetings::iceServers(),
            'base'     => url('/join/' . $meeting['public_token']),
            // 'blank', not 'public': the public layout is pinned to light
            // because the pages that use it stand for sheets of paper. A
            // meeting room is an interface, and should follow the theme.
        ], 'blank');
    }

    // -- Called from inside the room -----------------------------------

    public function postNote(Request $request): void
    {
        $meeting = $this->findOrFail((string) $request->param('token'));
        $name    = $this->nameFor($meeting);

        if (!$name) {
            Response::json(['ok' => false, 'error' => 'Please rejoin the meeting.'], 403);
        }

        $note = Meetings::addNote(
            (int) $meeting['id'],
            trim((string) $request->input('body', '')),
            $name,
            null,
            (string) $request->input('kind', 'note')
        );

        Response::json(['ok' => $note !== null, 'note' => $note]);
    }

    public function pollNotes(Request $request): void
    {
        $meeting = $this->findOrFail((string) $request->param('token'));

        Response::json([
            'ok'    => true,
            'notes' => Meetings::notes((int) $meeting['id'], (int) $request->query('since', 0)),
        ]);
    }

    public function signal(Request $request): void
    {
        $meeting = $this->findOrFail((string) $request->param('token'));
        $this->requireGuest($meeting);
        Meetings::handleSignal($request, (int) $meeting['id']);
    }

    public function signals(Request $request): void
    {
        $meeting = $this->findOrFail((string) $request->param('token'));
        $this->requireGuest($meeting);
        Meetings::deliverSignals($request, (int) $meeting['id']);
    }

    private function requireGuest(array $meeting): void
    {
        if (!$this->nameFor($meeting)) {
            Response::json(['ok' => false, 'error' => 'Please rejoin the meeting.'], 403);
        }
    }

    private function nameFor(array $meeting): ?string
    {
        $name = Session::get(self::NAME_KEY . $meeting['id']);

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Find the meeting behind a share link.
     *
     * Refuses on anything that is not currently a room worth entering, and
     * says why — a client clicking a link an hour late deserves better than
     * a bare 404.
     */
    private function findOrFail(string $token): array
    {
        $token = strtolower(trim($token));

        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            throw new HttpException(404, 'That meeting link is not valid.');
        }

        $meeting = Database::first(
            'SELECT * FROM meetings WHERE public_token = :t LIMIT 1',
            ['t' => $token]
        );

        if (!$meeting) {
            throw new HttpException(404, 'That meeting link is not valid. Please ask for a new one.');
        }

        if (!$meeting['allow_guests']) {
            throw new HttpException(403, 'This meeting is open to Shanfix staff only.');
        }

        if ($meeting['status'] === 'cancelled') {
            throw new HttpException(410, 'This meeting was cancelled. Please contact us to rearrange.');
        }

        if ($meeting['status'] === 'ended') {
            throw new HttpException(410, 'This meeting has finished.');
        }

        return $meeting;
    }
}
