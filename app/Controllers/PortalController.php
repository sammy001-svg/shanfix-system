<?php
namespace App\Controllers;

use App\Core\ClientAuth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Settings;

/**
 * The client portal itself.
 *
 * Every query here is scoped to ClientAuth::clientId() and nothing else.
 * There is no path by which a signed-in client names a record: the client
 * they belong to comes from their session, so asking for somebody else's
 * invoice is not refused so much as impossible to express.
 */
class PortalController extends Controller
{
    public function home(Request $request): void
    {
        $me       = ClientAuth::user();
        $clientId = ClientAuth::clientId();

        if (!$me) {
            throw new HttpException(403, 'Please sign in.');
        }

        $counts = [
            'quotations' => 0,
            'invoices'   => 0,
            'owing'      => money(0),
            'owing_raw'  => 0.0,
        ];

        if ($clientId !== null) {
            $counts['quotations'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM documents
                  WHERE client_id = :c AND doc_type = 'quotation'
                    AND status <> 'draft' AND approval_status <> 'pending'",
                ['c' => $clientId],
                0
            );

            $counts['invoices'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM documents
                  WHERE client_id = :c AND doc_type = 'invoice'
                    AND status <> 'draft' AND approval_status <> 'pending'",
                ['c' => $clientId],
                0
            );

            $owing = (float) Database::scalar(
                "SELECT COALESCE(SUM(balance), 0) FROM documents
                  WHERE client_id = :c AND doc_type = 'invoice'
                    AND status NOT IN ('draft','cancelled','paid')
                    AND approval_status <> 'pending'",
                ['c' => $clientId],
                0
            );

            $counts['owing']     = money($owing);
            $counts['owing_raw'] = $owing;
        }

        $this->view('portal/home', [
            'title'   => 'Your account',
            'me'      => $me,
            'counts'  => $counts,
            'company' => Settings::company(),
        ], 'portal');
    }
}
