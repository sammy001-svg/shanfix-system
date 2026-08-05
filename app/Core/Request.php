<?php
namespace App\Core;

/**
 * Read-only view of the current HTTP request.
 */
class Request
{
    private array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        private array $query,
        private array $body,
        private array $files
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Allow <input name="_method" value="POST"> style overrides if ever needed.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self(
            $method,
            $_SERVER['REQUEST_URI'] ?? '/',
            $_GET ?? [],
            $_POST ?? [],
            $_FILES ?? []
        );
    }

    public function setRouteParams(array $params): void
    {
        $this->params = $params;
    }

    /** Route placeholder, e.g. {id} */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function paramInt(string $key): int
    {
        return (int) $this->param($key, 0);
    }

    /** Query string value */
    public function query(string $key, mixed $default = null): mixed
    {
        $val = $this->query[$key] ?? $default;
        return is_string($val) ? trim($val) : $val;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    /** POST body value, trimmed */
    public function input(string $key, mixed $default = null): mixed
    {
        $val = $this->body[$key] ?? $default;
        return is_string($val) ? trim($val) : $val;
    }

    public function int(string $key, int $default = 0): int
    {
        $val = $this->input($key);
        return $val === null || $val === '' ? $default : (int) $val;
    }

    public function decimal(string $key, float $default = 0.0): float
    {
        $val = $this->input($key);
        if ($val === null || $val === '') {
            return $default;
        }
        // Tolerate "1,250.00" typed by users
        return (float) str_replace(',', '', (string) $val);
    }

    public function bool(string $key): bool
    {
        $val = $this->input($key);
        return in_array($val, ['1', 'on', 'true', 'yes', 1, true], true);
    }

    /** @return array<int,mixed> */
    public function array(string $key): array
    {
        $val = $this->body[$key] ?? [];
        return is_array($val) ? $val : [];
    }

    public function all(): array
    {
        return $this->body;
    }

    /** Only the listed keys, for mass assignment */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->input($key);
        }
        return $out;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        return $this->isAjax()
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }
}
