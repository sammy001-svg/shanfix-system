<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Settings;
use App\Services\WhatsApp;

/**
 * The endpoint Meta calls.
 *
 * Public and sessionless, like the KopoKopo webhook: the caller is not a
 * person and has no cookies. What proves the call is genuine is the
 * signature on the body, checked against the app secret.
 *
 * Two jobs:
 *   GET   Meta's one-off handshake when the webhook is first configured
 *   POST  everything afterwards — messages in, and delivery receipts
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * The handshake.
     *
     * Meta calls once with a challenge and the verify token we gave it.
     * Echo the challenge back verbatim, but only if the token matches —
     * that is the whole check, and getting it wrong means anyone could
     * point their webhook at us.
     */
    public function verify(Request $request): void
    {
        $mode      = (string) $request->query('hub_mode', '');
        $token     = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        $expected = trim((string) Settings::get('whatsapp_verify_token', ''));

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }

        Logger::warning('WhatsApp webhook verification refused.');

        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Verification failed.';
        exit;
    }

    /**
     * Messages and receipts.
     *
     * Always answers 200 once the signature checks out, even if something
     * inside the payload confuses us. Meta retries anything else with
     * increasing delay and eventually disables the webhook, so a message
     * we cannot parse must not take the whole integration down — it goes
     * to the log instead.
     */
    public function receive(Request $request): void
    {
        $raw = file_get_contents('php://input') ?: '';

        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;

        if (!WhatsApp::signatureValid($raw, $signature)) {
            Logger::warning('WhatsApp webhook rejected: bad signature.');
            http_response_code(401);
            echo 'Invalid signature.';
            exit;
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            http_response_code(200);   // nothing to do, but do not make Meta retry
            echo 'ok';
            exit;
        }

        try {
            $this->process($payload);
        } catch (\Throwable $e) {
            Logger::error('WhatsApp webhook processing failed: ' . $e->getMessage());
        }

        http_response_code(200);
        header('Content-Type: text/plain');
        echo 'ok';
        exit;
    }

    /**
     * Walk the payload.
     *
     * Meta nests everything several levels deep and batches unrelated
     * things together, so each level is stepped through defensively rather
     * than reached into by index.
     */
    private function process(array $payload): void
    {
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                // Delivery receipts for things we sent.
                foreach (($value['statuses'] ?? []) as $status) {
                    WhatsApp::applyStatus($status);
                }

                if (empty($value['messages'])) {
                    continue;
                }

                // The sender's WhatsApp profile name, when they share it.
                $names = [];
                foreach (($value['contacts'] ?? []) as $contact) {
                    if (isset($contact['wa_id'])) {
                        $names[$contact['wa_id']] = $contact['profile']['name'] ?? null;
                    }
                }

                foreach ($value['messages'] as $message) {
                    $from = (string) ($message['from'] ?? '');

                    if ($from === '') {
                        continue;
                    }

                    $conversation = WhatsApp::conversationFor($from, $names[$from] ?? null);

                    WhatsApp::recordInbound($conversation, $message, $names[$from] ?? null);
                }
            }
        }
    }
}
