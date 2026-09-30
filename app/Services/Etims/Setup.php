<?php

namespace App\Services\Etims;

use App\Core\Settings;

/**
 * The company's own KRA details, and whether we are in a position to
 * send anything at all.
 *
 * Called Setup rather than Config because App\Core\Config already
 * exists and means something else entirely — anything importing both
 * would have to alias one of them, and the one that got aliased would
 * be whichever the author happened to write second.
 *
 * Kept apart from the sending because "are we set up" is asked in
 * several places that have no business knowing how a transmission
 * works — the settings page showing what is still missing, the invoice
 * page deciding whether to offer a Send button, the nightly sweep
 * deciding whether to do anything.
 *
 * The rule throughout is that a half-configured integration sends
 * nothing. An invoice KRA rejects is not an error message; it is a
 * compliance problem with a client's name on it, and the cost of
 * refusing to send is a queue somebody clears once the details are in.
 */
final class Setup
{
    /** OSCU: the company talks to KRA's API directly from this system. */
    public const MODE = 'oscu';

    /** Which of KRA's environments the details belong to. */
    public static function environment(): string
    {
        return Settings::get('etims_environment', 'sandbox') === 'production'
            ? 'production'
            : 'sandbox';
    }

    public static function isLive(): bool
    {
        return self::environment() === 'production';
    }

    /**
     * The taxpayer's PIN, as KRA holds it.
     *
     * The company's own PIN, from the Company tab — the one already
     * printed on every invoice. Not a second setting of its own: two
     * settings for one fact means the PIN on the paper and the PIN on
     * the declaration can differ, and the one that is wrong is whichever
     * the person was not looking at.
     */
    public static function pin(): string
    {
        return strtoupper(trim((string) Settings::get('company_kra_pin', '')));
    }

    /**
     * Which branch this system bills from.
     *
     * "00" is head office and is the right answer for a business with
     * one location, which is why it is the default rather than blank.
     */
    public static function branch(): string
    {
        $branch = trim((string) Settings::get('etims_branch_id', '00'));

        return $branch !== '' ? $branch : '00';
    }

    /** The serial KRA issued for this installation. */
    public static function deviceSerial(): string
    {
        return trim((string) Settings::get('etims_device_serial', ''));
    }

    public static function baseUrl(): string
    {
        return rtrim(trim((string) Settings::get('etims_base_url', '')), '/');
    }

    /** Whether the operator has switched sending on. */
    public static function enabled(): bool
    {
        return Settings::bool('etims_enabled', false);
    }

    /**
     * Whether an invoice goes as soon as it is raised.
     *
     * Off to begin with. The first weeks of an eTIMS integration are
     * spent finding out what KRA rejects, and finding that out one
     * invoice at a time is kinder than finding it out across a day's
     * billing.
     */
    public static function autoSend(): bool
    {
        return self::enabled() && Settings::bool('etims_auto_send', false);
    }

    /**
     * What is still missing, in words somebody can act on.
     *
     * @return array<int,string> empty when the details are complete
     */
    public static function missing(): array
    {
        $missing = [];

        if (self::pin() === '') {
            $missing[] = 'the company KRA PIN, on the Company tab';
        }

        if (self::deviceSerial() === '') {
            $missing[] = 'the device serial KRA issued for this installation';
        }

        if (self::baseUrl() === '') {
            $missing[] = 'the address of KRA\'s eTIMS service';
        }

        if (trim((string) Settings::get('etims_device_key', '')) === '') {
            $missing[] = 'the device credentials KRA gave you';
        }

        return $missing;
    }

    /** Ready to talk to KRA, whether or not anybody has switched it on. */
    public static function isConfigured(): bool
    {
        return self::missing() === [];
    }

    /**
     * Ready and switched on. Nothing sends unless this is true.
     */
    public static function canSend(): bool
    {
        return self::enabled() && self::isConfigured();
    }

    /**
     * One sentence for a screen: what state the integration is in.
     */
    public static function describe(): string
    {
        if (!self::isConfigured()) {
            return 'Not set up yet — ' . self::missing()[0] . ' is still missing.';
        }

        if (!self::enabled()) {
            return 'Set up, but switched off. Nothing is being sent to KRA.';
        }

        return self::isLive()
            ? 'Sending to KRA. Invoices sent from here are real.'
            : 'Sending to KRA\'s sandbox. Nothing here reaches the real system.';
    }
}
