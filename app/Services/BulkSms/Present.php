<?php
namespace App\Services\BulkSms;

/**
 * How Bulk SMS states read on screen.
 *
 * Shared by the office, the client portal and the partner portal, so a
 * campaign that is "Sending" to the customer is "Sending" to us too.
 * Every method returns [badge modifier, words].
 */
final class Present
{
    public static function campaign(string $status): array
    {
        return match ($status) {
            'draft'     => ['badge--grey',  'Draft'],
            'scheduled' => ['badge--blue',  'Scheduled'],
            'queued'    => ['badge--blue',  'Queued'],
            'sending'   => ['badge--amber', 'Sending'],
            'completed' => ['badge--green', 'Sent'],
            'failed'    => ['badge--red',   'Stopped'],
            'cancelled' => ['badge--grey',  'Cancelled'],
            default     => ['badge--grey',  ucfirst($status)],
        };
    }

    public static function message(string $status): array
    {
        return match ($status) {
            'queued'      => ['badge--grey',  'Queued'],
            'sent'        => ['badge--blue',  'Sent'],
            'delivered'   => ['badge--green', 'Delivered'],
            'failed'      => ['badge--red',   'Failed'],
            'undelivered' => ['badge--amber', 'Undelivered'],
            default       => ['badge--grey',  ucfirst($status)],
        };
    }

    public static function purchase(string $status): array
    {
        return match ($status) {
            'pending'   => ['badge--amber', 'Waiting'],
            'completed' => ['badge--green', 'Paid'],
            'failed'    => ['badge--red',   'Not paid'],
            'refunded'  => ['badge--grey',  'Refunded'],
            default     => ['badge--grey',  ucfirst($status)],
        };
    }

    public static function sender(string $status): array
    {
        return match ($status) {
            'pending'  => ['badge--amber', 'Waiting on the networks'],
            'approved' => ['badge--green', 'Approved'],
            'rejected' => ['badge--red',   'Not approved'],
            default    => ['badge--grey',  ucfirst($status)],
        };
    }

    /** The carrier's words for a delivery label, for people rather than Onfon. */
    public static function label(string $label): array
    {
        return match ($label) {
            'DelivredToTerminal', 'DELIVRD' => ['badge--green', $label === 'DELIVRD' ? 'Delivered' : 'Delivered to handset'],
            'Submitted'              => ['badge--blue',  'With the network'],
            'AbsentSubscriber'       => ['badge--amber', 'Phone off or out of range'],
            'DeliveryImpossible'     => ['badge--red',   'Could not be delivered'],
            'REJECTD'                => ['badge--red',   'Rejected by the network'],
            'Sendername blacklisted' => ['badge--red',   'Sender ID blocked'],
            'Expired'                => ['badge--grey',  'Expired undelivered'],
            default                  => ['badge--grey',  $label],
        };
    }

    public static function ledgerKind(string $kind): string
    {
        return match ($kind) {
            'purchase'     => 'Bought',
            'sale'         => 'Sold',
            'transfer_in'  => 'Transferred in',
            'transfer_out' => 'Transferred out',
            'send'         => 'Sent messages',
            'refund'       => 'Refund',
            'adjustment'   => 'Adjustment',
            'migration'    => 'Opening balance',
            default        => ucfirst($kind),
        };
    }

    /** "1,250.5" with no trailing zeros — units are shown as counts, not money. */
    public static function units(float|string|null $units): string
    {
        $formatted = number_format((float) $units, 2);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
