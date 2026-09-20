<?php
namespace App\Controllers;

use App\Core\ClientAuth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;
use App\Services\BulkSms\Accounts;

/**
 * Bulk SMS in the client portal.
 *
 * Everything a customer does with SMS is in SmsPortalBase, which the
 * partner portal shares. This only answers four questions: whose account,
 * where the pages live, which shell they are drawn in, and who to record
 * as having pressed the button.
 */
class PortalSmsController extends SmsPortalBase
{
    protected function account(): array
    {
        if (!Settings::bool('bulk_sms_enabled', true)) {
            throw new HttpException(404, 'Not found.');
        }

        $clientId = ClientAuth::clientId();

        if ($clientId === null) {
            throw new HttpException(403, 'Please sign in.');
        }

        // Opened on the first visit rather than for every client on file:
        // most will never send a text, and a row for each of them would
        // make "who uses SMS" a harder question than it needs to be.
        return Accounts::forClient($clientId) ?? throw new HttpException(404, 'No SMS account.');
    }

    protected function base(): string
    {
        return '/portal/sms';
    }

    protected function layout(): string
    {
        return 'portal';
    }

    protected function actorType(): string
    {
        return 'client_user';
    }

    protected function actorId(): ?int
    {
        return ClientAuth::id();
    }

    protected function source(): string
    {
        return 'portal';
    }

    /**
     * The signed-in person's own mobile first, then the company's. An
     * M-Pesa prompt should land on the phone of whoever is looking at the
     * screen, not on the switchboard.
     */
    protected function defaultPhone(array $account): string
    {
        $phone = (string) (Database::scalar(
            'SELECT phone FROM client_users WHERE id = :id',
            ['id' => ClientAuth::id()]
        ) ?? '');

        if ($phone === '' && $account['owner_type'] === 'client') {
            $phone = (string) (Database::scalar(
                'SELECT phone FROM clients WHERE id = :id',
                ['id' => $account['owner_id']]
            ) ?? '');
        }

        return $phone;
    }
}
