<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Settings;
use App\Services\Notifier;
use App\Services\Statement;

/**
 * A client's own statement of account, on a share link.
 *
 * No login — the 48-character token is the credential, exactly as it is
 * for a shared invoice. Read-only, and it shows nothing beyond this one
 * client's own invoices and payments.
 */
class PublicStatementController extends Controller
{
    public function show(Request $request): void
    {
        $client = $this->findByToken((string) $request->param('token'));

        // First open only — this records that the client saw it, which is a
        // different fact from us having sent it.
        if (empty($client['statement_viewed_at'])) {
            Database::update(
                'clients',
                ['statement_viewed_at' => date('Y-m-d H:i:s')],
                ['id' => $client['id']]
            );
        }

        $this->view('clients/statement', [
            'title'     => 'Statement · ' . $client['name'],
            // Always the full history on a client link: they have no way to
            // change the period, and a truncated one raises more questions
            // than it answers.
            'statement' => Statement::build($client, null, date('Y-m-d')),
            'company'   => Settings::company(),
            'isPublic'  => true,
            'shareLink' => '',
        ], 'print');
    }

    /**
     * Resolve the full token from an emailed link, or the short prefix used
     * in an SMS. An ambiguous prefix is refused rather than guessed at.
     */
    private function findByToken(string $token): array
    {
        $token = strtolower(trim($token));

        if (!preg_match('/^[a-f0-9]{48}$/', $token)
            && !preg_match('/^[a-f0-9]{' . Notifier::SHORT_TOKEN_LENGTH . '}$/', $token)) {
            throw new HttpException(404, 'This link is not valid.');
        }

        $isShort = strlen($token) === Notifier::SHORT_TOKEN_LENGTH;

        $rows = Database::all(
            'SELECT * FROM clients
              WHERE ' . ($isShort ? 'LEFT(public_token, :len) = :token' : 'public_token = :token') . '
              LIMIT 2',
            $isShort
                ? ['len' => Notifier::SHORT_TOKEN_LENGTH, 'token' => $token]
                : ['token' => $token]
        );

        if (count($rows) !== 1) {
            throw new HttpException(404, 'This link is no longer valid. Please ask us to resend it.');
        }

        return $rows[0];
    }
}
