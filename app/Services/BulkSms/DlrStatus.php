<?php
namespace App\Services\BulkSms;

/**
 * Delivery status vocabulary — ported unchanged from the old platform.
 *
 * One place that decides what a carrier delivery state is called, so the
 * delivery-report webhook and the Delivery Reports pages can never
 * disagree. Labels match the Onfon portal's own report columns exactly,
 * including its spelling of "DelivredToTerminal", so our report
 * reconciles against theirs line for line.
 *
 * Two states are recorded per message:
 *   bulk_messages.status     — the 5-value ENUM the rest of the code uses.
 *   bulk_messages.dlr_status — one of CANONICAL, or the raw carrier string
 *                              when it is something not seen before.
 */
final class DlrStatus {

    /**
     * The report's fixed columns, in display order. Rendered even when a status
     * has not occurred in the period, which is what the Onfon report does.
     */
    const CANONICAL = [
        'DelivredToTerminal',
        'Submitted',
        'AbsentSubscriber',
        'DeliveryImpossible',
        'DELIVRD',
        'REJECTD',
        'Sendername blacklisted',
        'Expired',
    ];

    /** Carrier states that mean the handset received the message. */
    const DELIVERED = ['DelivredToTerminal', 'DELIVRD'];

    /** Carrier states that mean it will never arrive. */
    const FAILED = ['AbsentSubscriber', 'DeliveryImpossible', 'REJECTD', 'Sendername blacklisted', 'Expired'];

    /** Carrier states that are still in flight — not terminal. */
    const PENDING = ['Submitted'];

    /**
     * Map any raw carrier value — numeric code or status string — onto the
     * canonical vocabulary.
     *
     * An unrecognised, non-empty value is returned trimmed and unchanged rather
     * than forced into a bucket, so a new carrier state shows up in the report
     * as its own column instead of being silently mislabelled.
     */
    public static function normalise($raw): string {
        $trimmed = trim((string)$raw);
        if ($trimmed === '') return 'Unknown';

        // Numeric Onfon codes.
        if (is_numeric($trimmed)) {
            switch ((int)$trimmed) {
                case 1:
                case 2:  return 'DELIVRD';
                case 3:  return 'DeliveryImpossible';
                case 4:  return 'Expired';
                default: return 'Submitted';   // 0 / 5 — buffered, still moving
            }
        }

        // Compare letters only, so "Sendername blacklisted", "sendername_blacklisted"
        // and "SENDERNAME BLACKLISTED" all land on the same column.
        $key = strtolower(preg_replace('/[^a-z]/i', '', $trimmed));

        // Longest/most specific patterns first — "deliveredtoterminal" must not
        // be caught by the looser "delivered" test below it.
        static $map = [
            'delivredtoterminal'    => 'DelivredToTerminal',
            'deliveredtoterminal'   => 'DelivredToTerminal',
            'delivredtoterm'        => 'DelivredToTerminal',
            'deliveredtoterm'       => 'DelivredToTerminal',

            'absentsubscriber'      => 'AbsentSubscriber',
            'absentsubscribertemp'  => 'AbsentSubscriber',
            'unknownsubscriber'     => 'AbsentSubscriber',
            'invalidnumber'         => 'AbsentSubscriber',

            'deliveryimpossible'    => 'DeliveryImpossible',
            'deliveryfailed'        => 'DeliveryImpossible',
            'undeliverable'         => 'DeliveryImpossible',
            'undelivered'           => 'DeliveryImpossible',
            'undeliv'               => 'DeliveryImpossible',
            'notdelivered'          => 'DeliveryImpossible',
            'failed'                => 'DeliveryImpossible',

            'sendernameblacklisted' => 'Sendername blacklisted',
            'senderidblacklisted'   => 'Sendername blacklisted',
            'blacklisted'           => 'Sendername blacklisted',

            'rejectd'               => 'REJECTD',
            'rejected'              => 'REJECTD',

            'expired'               => 'Expired',
            'deleted'               => 'Expired',

            'deliveredtonetwork'    => 'Submitted',
            'submitted'             => 'Submitted',
            'acceptd'               => 'Submitted',
            'accepted'              => 'Submitted',
            'enroute'               => 'Submitted',
            'buffered'              => 'Submitted',
            'pending'               => 'Submitted',
            'queued'                => 'Submitted',
            'sent'                  => 'Submitted',

            'delivrd'               => 'DELIVRD',
            'delivered'             => 'DELIVRD',
            'success'               => 'DELIVRD',
            'ok'                    => 'DELIVRD',
        ];

        if (isset($map[$key])) return $map[$key];

        // Substring fallback for values that arrive with extra wording, e.g.
        // "Delivery impossible (absent subscriber)".
        foreach ($map as $needle => $label) {
            if (strlen($needle) >= 7 && strpos($key, $needle) !== false) return $label;
        }

        // Onfon send-time failure text (messages.failed_reason). Without a
        // carrier receipt this is the most specific thing we hold, so it is
        // what lets AbsentSubscriber and Sendername blacklisted populate at all.
        // Mirrored in SQL by the report's derived-label CASE.
        static $reasons = [
            'AbsentSubscriber'       => ['unregisteredmobile', 'invalidorunregistered', 'invalidmobile', 'invalidnumber', 'absent'],
            'Sendername blacklisted' => ['senderidnotapproved', 'sendernotapproved', 'senderid', 'blacklist'],
            'DeliveryImpossible'     => ['networknotsupported', 'mobilenetworknot', 'gatewayerror', 'internalservererror', 'insufficient', 'texttoolong'],
            'Expired'                => ['expired', 'timedout'],
        ];
        foreach ($reasons as $label => $needles) {
            foreach ($needles as $needle) {
                if (strpos($key, $needle) !== false) return $label;
            }
        }

        // New to us — keep it verbatim so it is visible rather than mislabelled.
        return mb_substr($trimmed, 0, 60);
    }

    /**
     * Our 5-value ENUM for a canonical label, or null when the message is still
     * in flight and messages.status must be left alone.
     */
    public static function toEnum(string $canonical): ?string {
        if (in_array($canonical, self::DELIVERED, true)) return 'delivered';
        if (in_array($canonical, self::FAILED, true))    return 'undelivered';
        return null;   // Submitted, Unknown, or an unrecognised carrier state
    }

    /** Delivered / Pending / Failed, for the report's Aggregate Status mode. */
    public static function bucket(string $canonical): string {
        if (in_array($canonical, self::DELIVERED, true)) return 'Delivered';
        if (in_array($canonical, self::FAILED, true))    return 'Failed';
        if (in_array($canonical, self::PENDING, true))   return 'Pending';
        // An uncatalogued carrier state is not evidence of failure, and
        // toEnum() likewise refuses to guess — keep the two consistent.
        return 'Unknown';
    }

    /**
     * Column for a messages.failed_reason, mirroring the report's SQL CASE
     * exactly. Any unmatched reason lands on DeliveryImpossible, so an
     * account-level error such as "Invalid Onfon API key" is not promoted into
     * a column of its own — SQL would have bucketed it, and the two must agree.
     *
     * Use this for failed_reason; use normalise() for carrier status values.
     */
    public static function fromFailureReason(string $reason): string {
        $key = strtolower(preg_replace('/[^a-z]/i', '', $reason));
        if ($key === '') return 'DeliveryImpossible';

        foreach (['unregistered', 'invalidnumber', 'invalidmobile', 'absent'] as $n) {
            if (strpos($key, $n) !== false) return 'AbsentSubscriber';
        }
        foreach (['sendernotapproved', 'senderidnotapproved', 'senderid', 'blacklist'] as $n) {
            if (strpos($key, $n) !== false) return 'Sendername blacklisted';
        }
        foreach (['expired', 'timedout'] as $n) {
            if (strpos($key, $n) !== false) return 'Expired';
        }
        if (strpos($key, 'reject') !== false) return 'REJECTD';

        return 'DeliveryImpossible';
    }

    /**
     * Best-guess label for a message that never received a delivery receipt,
     * derived from our own ENUM. Keeps historical rows visible in the report.
     * Mirrored in SQL by the report query.
     */
    public static function fromEnum(string $status): string {
        switch ($status) {
            case 'delivered':   return 'DELIVRD';
            case 'sent':        return 'Submitted';
            case 'failed':      return 'REJECTD';
            case 'undelivered': return 'DeliveryImpossible';
            case 'queued':      return 'Submitted';
            default:            return 'Unknown';
        }
    }
}
