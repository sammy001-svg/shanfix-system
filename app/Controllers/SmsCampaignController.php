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
use App\Core\Settings;
use App\Services\Sms;

/**
 * One message to many clients.
 *
 * Sending is deliberately two steps. The first resolves the audience and
 * prices it against the account balance; only then does a send button
 * appear. Spending several thousand shillings of SMS credit should not be
 * one mis-click away, and "how many will this reach" is the question
 * everyone asks before pressing go.
 */
class SmsCampaignController extends Controller
{
    /** Matches the gateway's own ceiling; longer lists are chunked by Sms. */
    private const MAX_RECIPIENTS = 5000;

    private const AUDIENCES = [
        'active'      => 'All active clients',
        'all'         => 'Every client, active or not',
        'company'     => 'Companies only',
        'individual'  => 'Individuals only',
        'owing'       => 'Clients with an unpaid balance',
        'recent'      => 'Clients invoiced in the last 90 days',
    ];

    public function index(Request $request): void
    {
        $this->authorize('sms.campaign');

        $total = (int) Database::scalar('SELECT COUNT(*) FROM sms_campaigns', [], 0);
        $pager = $this->paginate($total, 25);

        $campaigns = Database::all(
            "SELECT c.*, u.name AS sent_by
               FROM sms_campaigns c
          LEFT JOIN users u ON u.id = c.created_by
           ORDER BY c.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}"
        );

        $this->view('sms/index', [
            'title'     => 'SMS campaigns',
            'campaigns' => $campaigns,
            'pager'     => $pager,
            'smsOn'     => Settings::bool('sms_enabled'),
        ]);
    }

    /** The composer. Also renders the priced preview when one is asked for. */
    public function create(Request $request): void
    {
        $this->authorize('sms.campaign');

        $this->view('sms/form', [
            'title'     => 'New SMS campaign',
            'audiences' => self::AUDIENCES,
            'smsOn'     => Settings::bool('sms_enabled'),
            'form'      => ['title' => '', 'audience' => 'active', 'message' => ''],
            'preview'   => null,
        ]);
    }

    /**
     * Resolve the audience and price it, without sending anything.
     *
     * The balance comes from the gateway rather than a local tally, so the
     * figure shown is the one that will actually be spent against.
     */
    public function preview(Request $request): void
    {
        $this->authorize('sms.campaign');

        $form = $this->formInput($request);
        $errors = $this->validate($form);

        if ($errors !== []) {
            $this->view('sms/form', [
                'title'     => 'New SMS campaign',
                'audiences' => self::AUDIENCES,
                'smsOn'     => Settings::bool('sms_enabled'),
                'form'      => $form,
                'preview'   => null,
                'errors'    => $errors,
            ]);
            return;
        }

        $recipients = $this->resolveAudience($form['audience']);
        $parts      = Sms::parts($form['message']);

        $sms     = new Sms();
        $balance = $sms->isConfigured() ? $sms->balance() : ['ok' => false, 'error' => 'SMS is not configured.'];

        $this->view('sms/form', [
            'title'     => 'New SMS campaign',
            'audiences' => self::AUDIENCES,
            'smsOn'     => Settings::bool('sms_enabled'),
            'form'      => $form,
            'preview'   => [
                'recipients'  => $recipients,
                'count'       => count($recipients),
                'parts'       => $parts,
                'credits'     => $parts * count($recipients),
                'balance'     => $balance['ok'] ? $balance['balance'] : null,
                'balanceNote' => $balance['ok'] ? null : ($balance['error'] ?? 'Balance unavailable.'),
                // Spent on send; a double submit then finds the key already
                // used and stops rather than texting everyone twice.
                'token'       => bin2hex(random_bytes(16)),
            ],
        ]);
    }

    public function send(Request $request): void
    {
        $this->authorize('sms.campaign');

        // A refreshed confirmation page must not send the campaign again.
        $this->guardReplay($request, '/sms-campaigns');

        if (!Settings::bool('sms_enabled')) {
            Session::error('SMS is switched off in Settings, so nothing was sent.');
            Response::to('/sms-campaigns/new');
        }

        $form   = $this->formInput($request);
        $errors = $this->validate($form);

        if ($errors !== []) {
            Session::error(reset($errors));
            Response::to('/sms-campaigns/new');
        }

        // Re-resolved rather than trusting the preview: the client list may
        // have moved on, and the list is what we are about to charge for.
        $recipients = $this->resolveAudience($form['audience']);

        if ($recipients === []) {
            Session::error('That audience has nobody with a usable phone number.');
            Response::to('/sms-campaigns/new');
        }

        $campaignId = Database::insert('sms_campaigns', [
            'title'      => $form['title'],
            'message'    => $form['message'],
            'audience'   => self::AUDIENCES[$form['audience']] ?? $form['audience'],
            'parts'      => Sms::parts($form['message']),
            'recipients' => count($recipients),
            'status'     => 'sending',
            'created_by' => Auth::id(),
        ]);

        foreach ($recipients as $r) {
            Database::insert('sms_campaign_recipients', [
                'campaign_id' => $campaignId,
                'client_id'   => $r['id'],
                'name'        => $r['name'],
                'phone'       => $r['phone'],
            ]);
        }

        $result = (new Sms())->sendBulk(array_column($recipients, 'phone'), $form['message']);

        $this->recordOutcome($campaignId, $result);

        ActivityLog::record(
            'sms_campaign_sent',
            'sms_campaign',
            $campaignId,
            $form['title'] . ' to ' . count($recipients) . ' recipient(s)'
        );

        if (!empty($result['ok'])) {
            Session::success(
                'Campaign sent: ' . (int) ($result['sent'] ?? 0) . ' delivered to the gateway'
                . (!empty($result['failed']) ? ', ' . (int) $result['failed'] . ' failed' : '')
                . '. ' . (int) count($result['invalid'] ?? []) . ' number(s) skipped.'
            );
        } else {
            Session::error('The campaign could not be sent: ' . ($result['error'] ?? 'unknown error'));
        }

        Response::to('/sms-campaigns/' . $campaignId);
    }

    public function show(Request $request): void
    {
        $this->authorize('sms.campaign');

        $id = $request->paramInt('id');

        $campaign = Database::first(
            'SELECT c.*, u.name AS sent_by
               FROM sms_campaigns c
          LEFT JOIN users u ON u.id = c.created_by
              WHERE c.id = :id',
            ['id' => $id]
        );

        if (!$campaign) {
            throw new HttpException(404, 'That campaign does not exist.');
        }

        $this->view('sms/show', [
            'title'      => $campaign['title'],
            'campaign'   => $campaign,
            'recipients' => Database::all(
                'SELECT * FROM sms_campaign_recipients WHERE campaign_id = :id ORDER BY status, name',
                ['id' => $id]
            ),
        ]);
    }

    // -- internals -------------------------------------------------------

    /** @return array{title:string, audience:string, message:string} */
    private function formInput(Request $request): array
    {
        return [
            'title'    => trim((string) $request->input('title', '')),
            'audience' => (string) $request->input('audience', 'active'),
            'message'  => trim((string) $request->input('message', '')),
        ];
    }

    /** @return array<string,string> field => message */
    private function validate(array $form): array
    {
        $errors = [];

        if ($form['title'] === '') {
            $errors['title'] = 'Give the campaign a name so you can find it later.';
        } elseif (mb_strlen($form['title']) > 140) {
            $errors['title'] = 'Keep the name under 140 characters.';
        }

        if (!isset(self::AUDIENCES[$form['audience']])) {
            $errors['audience'] = 'Choose who the message is going to.';
        }

        if ($form['message'] === '') {
            $errors['message'] = 'Write the message you want to send.';
        } elseif (mb_strlen($form['message']) > 918) {
            $errors['message'] = 'The gateway will not accept more than 918 characters.';
        }

        return $errors;
    }

    /**
     * Clients matching the chosen audience who have a usable number.
     *
     * De-duplicated by normalised phone, because one person listed twice
     * should not be texted twice or billed for twice.
     *
     * @return array<int,array{id:int,name:string,phone:string}>
     */
    private function resolveAudience(string $audience): array
    {
        $where  = ["c.phone IS NOT NULL", "c.phone <> ''"];
        $params = [];

        switch ($audience) {
            case 'all':
                break;
            case 'company':
                $where[] = "c.status = 'active'";
                $where[] = "c.client_type = 'company'";
                break;
            case 'individual':
                $where[] = "c.status = 'active'";
                $where[] = "c.client_type = 'individual'";
                break;
            case 'owing':
                $where[] = "EXISTS (SELECT 1 FROM documents d
                                     WHERE d.client_id = c.id
                                       AND d.doc_type = 'invoice'
                                       AND d.status NOT IN ('cancelled','paid','draft')
                                       AND d.balance > 0)";
                break;
            case 'recent':
                $where[] = "EXISTS (SELECT 1 FROM documents d
                                     WHERE d.client_id = c.id
                                       AND d.doc_type = 'invoice'
                                       AND d.issue_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY))";
                break;
            case 'active':
            default:
                $where[] = "c.status = 'active'";
        }

        $rows = Database::all(
            'SELECT c.id, c.name, c.phone FROM clients c WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.name LIMIT ' . self::MAX_RECIPIENTS,
            $params
        );

        $seen = [];
        $out  = [];

        foreach ($rows as $row) {
            $phone = normalize_phone((string) $row['phone']);

            if ($phone === null || isset($seen[$phone])) {
                continue;
            }

            $seen[$phone] = true;
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'phone' => $phone];
        }

        return $out;
    }

    /** Write back whatever the gateway reported, including a total failure. */
    private function recordOutcome(int $campaignId, array $result): void
    {
        $invalid = (array) ($result['invalid'] ?? []);

        if (empty($result['ok'])) {
            Database::update('sms_campaigns', [
                'status'       => 'failed',
                'error'        => mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 500),
                'sent'         => (int) ($result['sent'] ?? 0),
                'failed'       => (int) ($result['failed'] ?? 0),
                'invalid'      => count($invalid),
                'cost'         => $result['cost'] ?? null,
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $campaignId]);
        } else {
            $failed = (int) ($result['failed'] ?? 0);

            Database::update('sms_campaigns', [
                'status'        => $failed > 0 ? 'partial' : 'sent',
                'sent'          => (int) ($result['sent'] ?? 0),
                'failed'        => $failed,
                'invalid'       => count($invalid),
                'cost'          => $result['cost'] ?? null,
                'balance_after' => $result['balance'] ?? null,
                'completed_at'  => date('Y-m-d H:i:s'),
            ], ['id' => $campaignId]);
        }

        // Mark the numbers the gateway would not take, so the recipient list
        // is honest about who was never going to receive it.
        foreach ($invalid as $bad) {
            $normalised = normalize_phone((string) $bad) ?? (string) $bad;

            Database::run(
                "UPDATE sms_campaign_recipients
                    SET status = 'skipped', reason = 'Rejected by the gateway'
                  WHERE campaign_id = :c AND phone = :p",
                ['c' => $campaignId, 'p' => $normalised]
            );
        }
    }
}
