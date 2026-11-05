<?php

/**
 * The small pieces of partner chrome, written once.
 *
 * The admin side and the partner's own portal both show who a partner
 * is and what tier they are on. Two copies of that markup drift, and
 * then a partner sees themselves described one way by us and another
 * way by their own dashboard. One set of helpers, used by both.
 *
 * Guarded with function_exists because the partner portal and the staff
 * layout can both pull this in within one request.
 */

use App\Services\Partners;

if (!function_exists('partner_initials')) {
    /**
     * One or two letters for the monogram.
     *
     * Taken from the trading name where there is one, because that is
     * the name the rest of the page leads with.
     */
    function partner_initials(array $partner): string
    {
        $source = trim((string) ($partner['company'] ?? ''))
            ?: trim((string) ($partner['name'] ?? ''));

        $words = preg_split('/\s+/', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        // Words that are not the name: a monogram of "LT" for
        // "Pearl Systems Ltd" tells nobody anything.
        $skip  = ['ltd', 'limited', 'llc', 'inc', 'plc', 'co', 'company',
                  'the', 'and', '&', 'group', 'fz-llc', 'enterprises'];
        $kept  = [];

        foreach ($words as $word) {
            $bare = strtolower(trim($word, ".,-'"));

            if ($bare !== '' && !in_array($bare, $skip, true)) {
                $kept[] = $word;
            }
        }

        $kept = $kept ?: $words;

        $first = mb_substr($kept[0], 0, 1);
        $last  = count($kept) > 1 ? mb_substr($kept[count($kept) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }
}

if (!function_exists('partner_tier_key')) {
    /** The tier code reduced to one of the four we have a colour for. */
    function partner_tier_key(?string $tier): string
    {
        $tier = strtolower(trim((string) $tier));

        return in_array($tier, ['registered', 'bronze', 'silver', 'gold'], true)
            ? $tier
            : 'registered';
    }
}

if (!function_exists('partner_mark')) {
    /**
     * The monogram tile, ringed in the partner's tier colour.
     *
     * @param string $size '' | 'lg' | 'sm'
     */
    function partner_mark(array $partner, string $size = ''): string
    {
        $key   = partner_tier_key($partner['tier'] ?? null);
        $class = 'pnr-mark pnr-mark--' . $key . ($size !== '' ? ' pnr-mark--' . $size : '');

        return sprintf(
            '<span class="%s" aria-hidden="true">%s</span>',
            e($class),
            e(partner_initials($partner))
        );
    }
}

if (!function_exists('partner_tier_pill')) {
    /** The tier, said in words with its colour beside it. */
    function partner_tier_pill(?string $tier): string
    {
        $key  = partner_tier_key($tier);
        $name = Partners::tierName($tier ?: $key);

        return sprintf(
            '<span class="pnr-tier pnr-tier--%s">'
            . '<span class="pnr-tier__dot">%s</span>%s</span>',
            e($key),
            e(mb_strtoupper(mb_substr($name, 0, 1))),
            e($name)
        );
    }
}

if (!function_exists('partner_metric')) {
    /**
     * One figure, its label, and the line that gives it meaning.
     *
     * The note matters more than it looks: without it a brand new
     * partner sees three cards reading 0.00 and cannot tell whether
     * that is the truth or a page that failed to load.
     */
    function partner_metric(
        string $value,
        string $label,
        string $note = '',
        string $tone = '',
        string $href = ''
    ): string {
        // A link where there is somewhere to go. The partner's own
        // portal sends each figure to the page behind it, which is how
        // the three cards replace a second row of them further down.
        $tag  = $href !== '' ? 'a' : 'div';
        $attr = $href !== '' ? ' href="' . e($href) . '"' : '';

        return sprintf(
            '<%s class="pnr-metric%s%s"%s>'
            . '<div class="pnr-metric__value">%s</div>'
            . '<div class="pnr-metric__label">%s</div>'
            . '%s</%s>',
            $tag,
            $tone !== '' ? ' pnr-metric--' . e($tone) : '',
            $href !== '' ? ' pnr-metric--link' : '',
            $attr,
            e($value),
            e($label),
            $note !== '' ? '<div class="pnr-metric__note">' . e($note) . '</div>' : '',
            $tag
        );
    }
}

if (!function_exists('partner_climb')) {
    /**
     * How far this partner is from the next tier up.
     *
     * "Next" means the tier above the one they are ON, not simply the
     * next threshold above what they have billed. Those are different
     * the moment somebody is placed on a tier they have not yet earned
     * — and a partner sitting on Gold being told they need another
     * 500,000 to reach Bronze is the sort of thing that makes a page
     * look like nobody read it.
     *
     * The figure is what their customers have been invoiced in twelve
     * months: the same number the thresholds are written against, so
     * the bar and the promotion can never disagree.
     *
     * Returns '' when tiers are not set up at all.
     */
    function partner_climb(float $billed, ?string $currentTier): string
    {
        $tiers = array_values(Partners::tiers());

        if ($tiers === []) {
            return '';
        }

        $key     = partner_tier_key($currentTier);
        $current = null;

        foreach ($tiers as $tier) {
            if ($tier['code'] === $key) {
                $current = $tier;
            }
        }

        $currentSort  = (int) ($current['sort'] ?? 0);
        $currentFloor = (float) ($current['min_billed_12m'] ?? 0);

        $next = null;

        foreach ($tiers as $tier) {
            if ((int) $tier['sort'] > $currentSort) {
                $next = $tier;
                break;
            }
        }

        $line = static fn(string $left, string $right, string $fill, int $pct): string => sprintf(
            '<div class="pnr-climb">'
            . '<div class="pnr-climb__head">'
            . '<span class="pnr-climb__to">%s</span>'
            . '<span class="text-xs text-muted">%s</span>'
            . '</div>'
            . '<div class="pnr-climb__bar"><div class="pnr-climb__fill%s" style="width:%d%%"></div></div>'
            . '</div>',
            $left,
            e($right),
            $fill,
            $pct
        );

        $twelve = money($billed, false) . ' billed in twelve months';

        // Already on the highest tier there is. Whether they have earned
        // it is a separate and useful question, so it is answered rather
        // than glossed over.
        if ($next === null) {
            $earned = $billed >= $currentFloor;

            return $line(
                $earned
                    ? 'On the top tier, and earning it.'
                    : 'On the top tier, though <strong>' . e(money($currentFloor - $billed, false))
                      . '</strong> short of what it normally takes.',
                $twelve,
                ' pnr-climb__fill--gold',
                $earned ? 100 : max(2, min(99, (int) round(($billed / max(0.01, $currentFloor)) * 100)))
            );
        }

        $target = (float) $next['min_billed_12m'];

        // Measured across the gap being crossed rather than from zero, so
        // the bar says how close the next step is and not how big the
        // whole ladder was.
        $span = max(0.01, $target - $currentFloor);
        $pct  = max(2, min(100, (int) round((($billed - $currentFloor) / $span) * 100)));

        return $line(
            sprintf(
                '%s more to <strong>%s</strong>',
                e(money(max(0, $target - $billed), false)),
                e((string) $next['name'])
            ),
            $twelve,
            $next['code'] === 'gold' ? ' pnr-climb__fill--gold' : '',
            $pct
        );
    }
}
