<?php

namespace App\Services\Etims;

use App\Core\Logger;
use App\Core\Settings;

/**
 * The wire to KRA.
 *
 * OSCU — the Online Sales Control Unit — means this system talks to
 * KRA's service directly rather than through a device on the counter.
 * That is the arrangement chosen for this business.
 *
 * ---------------------------------------------------------------------
 * THIS DOES NOT SEND ANYTHING YET, AND THAT IS DELIBERATE.
 *
 * Everything around it is finished: an invoice carries a tax class and
 * a KRA item code on every line, it has a standing with KRA, there is a
 * queue with retries, a log of every attempt, and somebody is told when
 * a transmission gives up. What is missing is one thing, and it is not
 * a thing that can be worked out from first principles — KRA's actual
 * request and response contract:
 *
 *   - the field names and shape of a sale payload
 *   - how a device authenticates, and whether the credential is a
 *     header, a signed body, or an exchange for a short-lived token
 *   - which endpoint a sale goes to, and which a credit note goes to
 *   - what a rejection looks like, so a rejection can be told apart
 *     from a network failure — the first must not be retried, the
 *     second must
 *
 * Guessing any of that produces invoices KRA rejects. A rejected
 * invoice is not an error message to iterate on: it is a filing the
 * business has not made, with a client's name on it, and the way it is
 * found out is usually an audit. So this refuses, clearly, and says
 * what it needs, which is better than a payload built from plausible
 * field names that happens to be wrong in one of them.
 *
 * To finish it, the only method that changes is sendInvoice(): build
 * the payload from $doc and $items, post it, map the reply into the
 * 'fiscal' keys below. The queue, the retries, the log, the printed
 * invoice and the tax arithmetic all stay exactly as they are.
 * ---------------------------------------------------------------------
 */
final class Oscu
{
    /** How long to wait on KRA before calling it a failure. */
    private const TIMEOUT = 20;

    /**
     * Send one sale.
     *
     * @param array<string,mixed>            $doc   the documents row
     * @param array<int,array<string,mixed>> $items its lines
     *
     * @return array{
     *   ok:bool, error?:string, http?:int, raw?:string, request?:string,
     *   fiscal?:array{
     *     receipt_no?:string, invoice_no?:string, signature?:string,
     *     internal_data?:string, signed_at?:string, verify_url?:string
     *   }
     * }
     */
    public static function sendInvoice(array $doc, array $items): array
    {
        if (!Setup::canSend()) {
            return ['ok' => false, 'error' => Setup::describe()];
        }

        // Everything that can be checked without knowing KRA's contract
        // is checked here, because these are the failures that would
        // otherwise be discovered as a rejection.
        $problem = self::whatIsWrongWith($doc, $items);

        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem];
        }

        return [
            'ok'    => false,
            'error' => 'The connection to KRA is not finished. ' . self::whatIsNeeded(),
        ];
    }

    /**
     * What is still needed to finish this, in one sentence somebody can
     * forward to whoever holds the KRA paperwork.
     */
    public static function whatIsNeeded(): string
    {
        return 'It needs KRA\'s OSCU API documentation for the '
             . Setup::environment() . ' environment — the sale payload, '
             . 'how the device authenticates, and what a rejection looks '
             . 'like — before anything is sent under this company\'s PIN.';
    }

    /** Whether the wire is actually implemented. */
    public static function isImplemented(): bool
    {
        return false;
    }

    /**
     * The invoice's own problems, found before anybody blames KRA.
     *
     * Every one of these would come back as a rejection, and a
     * rejection tells you far less than this does about which line of
     * which invoice is at fault.
     */
    public static function whatIsWrongWith(array $doc, array $items): ?string
    {
        if ($items === []) {
            return 'It has no lines on it.';
        }

        if (Setup::pin() === '') {
            return 'This business has no KRA PIN set.';
        }

        foreach ($items as $i => $item) {
            $n = $i + 1;

            if (!TaxTypes::isValid((string) ($item['tax_type'] ?? ''))) {
                return 'Line ' . $n . ' has no valid tax class on it.';
            }

            if (trim((string) ($item['etims_code'] ?? '')) === '') {
                return 'Line ' . $n . ' (' . mb_substr((string) $item['description'], 0, 40)
                     . ') has no KRA item code. Set one on the service or stock item.';
            }

            if ((float) ($item['quantity'] ?? 0) <= 0) {
                return 'Line ' . $n . ' has no quantity.';
            }
        }

        // The lines have to add up to what the client was billed, or the
        // declaration and the invoice are two different documents.
        $lineSum = 0.0;
        $taxSum  = 0.0;

        foreach ($items as $item) {
            $lineSum += (float) ($item['line_total'] ?? 0);
            $taxSum  += (float) ($item['tax_amount'] ?? 0);
        }

        if (abs($lineSum - (float) $doc['subtotal']) > 0.01) {
            return 'The lines come to ' . number_format($lineSum, 2)
                 . ' but the invoice says ' . number_format((float) $doc['subtotal'], 2) . '.';
        }

        if (abs($taxSum - (float) $doc['vat_amount']) > 0.01) {
            return 'The tax on the lines comes to ' . number_format($taxSum, 2)
                 . ' but the invoice says ' . number_format((float) $doc['vat_amount'], 2) . '.';
        }

        return null;
    }

    /**
     * A request to KRA, once there is one to make.
     *
     * Here rather than in the caller so that when the contract arrives
     * the retry rules, the timeout and the logging are already settled
     * and only the payload has to be written.
     *
     * @return array{ok:bool, http:int, body:string, error?:string}
     */
    private static function post(string $path, array $payload): array
    {
        $url = Setup::baseUrl() . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            // KRA's certificate is checked. An integration that turns
            // this off to make a sandbox work is an integration that
            // ships with it off.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => self::headers(),
        ]);

        $body  = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Logger::error('eTIMS could not reach KRA: ' . $error);

            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => $error];
        }

        return ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'body' => (string) $body];
    }

    /**
     * @return array<int,string>
     */
    private static function headers(): array
    {
        // The device credentials. Held encrypted at rest, and never
        // logged — the log keeps the request body, which is why they
        // are headers here and not fields in it.
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Device-Serial: ' . Setup::deviceSerial(),
            'X-Device-Key: ' . (string) Settings::get('etims_device_key', ''),
        ];
    }
}
