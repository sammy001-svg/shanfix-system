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

        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base   = ($script === '/' || $script === '.') ? '' : rtrim($script, '/');

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
    function asset(string $path): string
    {
        return url('assets/' . ltrim($path, '/'));
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
