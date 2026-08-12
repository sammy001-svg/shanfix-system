<?php
/**
 * Global helper functions available in controllers and views.
 */

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Settings;

if (!function_exists('e')) {
    /** Escape for HTML output. Use on every dynamic value in a view. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_path')) {
    /**
     * Sub-directory the app is served from, '' when at the domain root.
     * Lets the system work at both example.com/ and example.com/erp/.
     *
     * Normally detected from SCRIPT_NAME. Set app.base_path in config.php to
     * override — needed when a rewrite maps the domain root onto public/, so
     * SCRIPT_NAME says "/public/index.php" while visitors are on "/".
     */
    function base_path(): string
    {
        static $base = null;

        if ($base !== null) {
            return $base;
        }

        $configured = Config::get('app.base_path');

        if ($configured !== null) {
            $configured = trim((string) $configured, '/');
            $base = $configured === '' ? '' : '/' . $configured;
            return $base;
        }

        // SCRIPT_NAME is only meaningful when it actually points at the front
        // controller. Several servers — PHP's built-in one, and some FastCGI
        // setups — instead report the requested path whenever it looks like a
        // file, so a URL such as /files/uploads/logo.png yields a bogus base
        // of "/files/uploads" and the route silently fails to match.
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if (basename($script) !== 'index.php') {
            return $base = '';
        }

        // dirname() uses the platform separator, so on Windows "/index.php"
        // comes back as "\" — normalise after the call, not just before it.
        $dir = str_replace('\\', '/', dirname($script));

        $base = ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');

        return $base;
    }
}

if (!function_exists('url')) {
    /** Build an app URL: url('/clients/12') */
    function url(string $path = '/'): string
    {
        return base_path() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * URL for a file in public/assets, stamped with its modification time.
     *
     * Assets are served with a long cache lifetime for speed, which means a
     * browser will happily keep an old stylesheet for a week after an
     * upgrade — the page renders with markup its CSS has never seen.
     * Appending ?v={mtime} changes the URL whenever the file changes, so
     * the new version is fetched immediately and nobody has to be told to
     * hard-refresh.
     */
    function asset(string $path): string
    {
        $relative = ltrim($path, '/');
        $url      = url('assets/' . $relative);

        // Both layouts: public/assets in the standard tree, assets/ when the
        // flat cPanel build puts the front controller at the web root.
        foreach ([PUBLIC_PATH . '/assets/', BASE_PATH . '/assets/'] as $dir) {
            $file = $dir . $relative;

            if (is_file($file)) {
                return $url . '?v=' . filemtime($file);
            }
        }

        return $url;
    }
}

if (!function_exists('asset_path')) {
    /** Absolute path to a file in the assets folder, or null if absent. */
    function asset_path(string $relative): ?string
    {
        foreach ([PUBLIC_PATH . '/assets/', BASE_PATH . '/assets/'] as $dir) {
            $file = $dir . ltrim($relative, '/');

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}

if (!function_exists('inline_assets')) {
    /**
     * Whether to embed CSS/JS/images in the page rather than link to them.
     *
     * Two ways to turn it on, because the two arrive by different routes:
     * config.php is not in version control, so a deployment cannot switch it
     * — but Settings lives in the database and can be toggled from the admin
     * screen, which stays usable even when the page is rendering unstyled.
     * config wins when set, so a server can pin the behaviour regardless of
     * what anyone clicks.
     */
    function inline_assets(): bool
    {
        static $on = null;

        if ($on !== null) {
            return $on;
        }

        $configured = Config::get('app.inline_assets');

        if ($configured !== null) {
            return $on = (bool) $configured;
        }

        // Wrapped: this runs on every request including ones that fail before
        // the database is reachable, and a broken lookup must not take the
        // page down — linking assets is the safe answer either way.
        try {
            return $on = Settings::bool('inline_assets', false);
        } catch (\Throwable) {
            return $on = false;
        }
    }
}

if (!function_exists('css_tag')) {
    /**
     * The stylesheet, as a <link> or embedded in the page.
     *
     * Embedding exists for one situation: a proxy or "bot protection" layer
     * sitting in front of the site that answers requests for .css with an
     * HTML challenge page. The browser refuses HTML as a stylesheet, so the
     * page renders completely unstyled while the HTML itself loads fine.
     * Putting the CSS in the document means it arrives with the page and
     * cannot be intercepted separately.
     *
     * Costs bandwidth on every page, so it is off unless switched on — see
     * inline_assets(). Turn it back off once the interception stops; a
     * stylesheet the browser can cache once is faster than one repeated in
     * every page.
     */
    function css_tag(string $relative = 'css/app.css'): string
    {
        $file = asset_path($relative);

        if ($file !== null && inline_assets()) {
            return "<style>\n" . file_get_contents($file) . "\n</style>";
        }

        return '<link rel="stylesheet" href="' . e(asset($relative)) . '">';
    }
}

if (!function_exists('js_tag')) {
    /** The script, as a <script src> or embedded. See css_tag(). */
    function js_tag(string $relative = 'js/app.js'): string
    {
        $file = asset_path($relative);

        if ($file !== null && inline_assets()) {
            // The CSP forbids inline script, so this one carries a nonce.
            return '<script nonce="' . e(csp_nonce()) . '">' . "\n"
                 . file_get_contents($file) . "\n</script>";
        }

        return '<script src="' . e(asset($relative)) . '"></script>';
    }
}

if (!function_exists('inline_image')) {
    /**
     * A data: URI for an image file, or null to keep using its normal URL.
     *
     * Same purpose as css_tag(): when something in front of the site
     * intercepts separate file requests, an image embedded in the HTML still
     * arrives. Only used while app.inline_assets is on.
     *
     * Base64 inflates a file by a third and the result cannot be cached
     * separately, so anything large is left as a normal URL — a missing
     * background photo is a far smaller problem than a page that takes
     * several seconds to arrive on a phone.
     */
    function inline_image(?string $absolutePath, int $maxBytes = 400_000): ?string
    {
        if ($absolutePath === null
            || !inline_assets()
            || !is_file($absolutePath)
            || filesize($absolutePath) > $maxBytes) {
            return null;
        }

        $info = @getimagesize($absolutePath);
        $mime = $info['mime'] ?? null;

        if ($mime === null) {
            // getimagesize does not understand SVG; it is the one format here
            // that is text rather than a bitmap.
            if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) !== 'svg') {
                return null;
            }
            $mime = 'image/svg+xml';
        }

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($absolutePath));
    }
}

if (!function_exists('csp_nonce')) {
    /** One random nonce per request, shared by the CSP header and any inline script. */
    function csp_nonce(): string
    {
        static $nonce = null;

        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(16));
        }

        return $nonce;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('auth')) {
    function auth(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return Auth::can($permission);
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): string
    {
        return e(Session::old($key, $default));
    }
}

if (!function_exists('error_for')) {
    /** Field-level validation message, already escaped. */
    function error_for(array $errors, string $field): string
    {
        return isset($errors[$field])
            ? '<span class="field-error">' . e($errors[$field]) . '</span>'
            : '';
    }
}

if (!function_exists('money')) {
    /** 1250.5 -> "KES 1,250.50" */
    function money(float|string|null $amount, bool $withCurrency = true): string
    {
        $formatted = number_format((float) ($amount ?? 0), 2, '.', ',');
        return $withCurrency ? Settings::currency() . ' ' . $formatted : $formatted;
    }
}

if (!function_exists('money_short')) {
    /** Compact form for dashboard tiles: 1_250_000 -> "KES 1.25M" */
    function money_short(float|string|null $amount): string
    {
        $n   = (float) ($amount ?? 0);
        $cur = Settings::currency();
        $abs = abs($n);

        if ($abs >= 1_000_000) {
            return $cur . ' ' . rtrim(rtrim(number_format($n / 1_000_000, 2, '.', ''), '0'), '.') . 'M';
        }
        if ($abs >= 1_000) {
            return $cur . ' ' . rtrim(rtrim(number_format($n / 1_000, 1, '.', ''), '0'), '.') . 'K';
        }

        return $cur . ' ' . number_format($n, 0);
    }
}

if (!function_exists('qty')) {
    /** Drop trailing zeros: 12.00 -> "12", 12.50 -> "12.5" */
    function qty(float|string|null $n): string
    {
        $f = (float) ($n ?? 0);
        return rtrim(rtrim(number_format($f, 2, '.', ','), '0'), '.');
    }
}

if (!function_exists('fdate')) {
    function fdate(?string $date, string $format = 'd M Y'): string
    {
        if (!$date || str_starts_with($date, '0000')) {
            return '—';
        }
        $ts = strtotime($date);
        return $ts === false ? '—' : date($format, $ts);
    }
}

if (!function_exists('fdatetime')) {
    function fdatetime(?string $datetime): string
    {
        return fdate($datetime, 'd M Y, H:i');
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $datetime): string
    {
        if (!$datetime) {
            return '—';
        }

        $ts = strtotime($datetime);
        if ($ts === false) {
            return '—';
        }

        $diff = time() - $ts;

        if ($diff < 0)      return fdate($datetime);
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   return floor($diff / 60) . 'm ago';
        if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';

        return fdate($datetime);
    }
}

if (!function_exists('initials')) {
    function initials(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $name);
        $first = mb_substr($parts[0], 0, 1);
        $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }
}

if (!function_exists('normalize_phone')) {
    /**
     * Normalise a Kenyan number to the 2547XXXXXXXX form KopoKopo expects.
     * Returns null when the input cannot be interpreted.
     */
    function normalize_phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        // 0712345678 -> 254712345678
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '254' . substr($digits, 1);
        }

        // 712345678 -> 254712345678
        if (strlen($digits) === 9 && (str_starts_with($digits, '7') || str_starts_with($digits, '1'))) {
            return '254' . $digits;
        }

        // Already 254...
        if (strlen($digits) === 12 && str_starts_with($digits, '254')) {
            return $digits;
        }

        return null;
    }
}

if (!function_exists('status_badge')) {
    /** Map a document/lead status to a badge CSS modifier. */
    function status_badge(string $status): string
    {
        return match ($status) {
            'paid', 'accepted', 'won', 'completed', 'success', 'active' => 'badge--green',
            'partial', 'sent', 'contacted', 'qualified', 'pending'      => 'badge--amber',
            'overdue', 'rejected', 'lost', 'failed', 'cancelled', 'expired' => 'badge--red',
            'draft', 'new', 'inactive'                                   => 'badge--grey',
            'unpaid', 'proposal', 'negotiation'                          => 'badge--navy',
            default                                                      => 'badge--grey',
        };
    }
}

if (!function_exists('label_of')) {
    /** snake_case enum value -> "Snake Case" */
    function label_of(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }
}

if (!function_exists('is_active_nav')) {
    function is_active_nav(string $prefix): bool
    {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = '/' . ltrim(substr($uri, strlen(base_path())), '/');

        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Settings::get($key, $default);
    }
}

if (!function_exists('str_excerpt')) {
    function str_excerpt(?string $text, int $length = 80): string
    {
        $text = trim(strip_tags((string) $text));
        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1) . '…';
    }
}

if (!function_exists('query_string')) {
    /** Rebuild the current query string with overrides, for filters + paging. */
    function query_string(array $overrides = []): string
    {
        $params = array_merge($_GET, $overrides);
        $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
        return $params === [] ? '' : '?' . http_build_query($params);
    }
}
