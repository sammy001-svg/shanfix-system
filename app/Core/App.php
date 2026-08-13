<?php
namespace App\Core;

/**
 * Application kernel: boots config, DB, session, then dispatches the request.
 */
class App
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
    }

    public function boot(): self
    {
        Config::load(CONFIG_PATH . '/config.php');

        date_default_timezone_set(Config::get('app.timezone', 'Africa/Nairobi'));

        $debug = (bool) Config::get('app.debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

        Database::connect(Config::get('db'));
        Session::start();

        // No session but a "keep me signed in" cookie? Restore the login
        // before routing, so the user never sees the sign-in page.
        if (!Session::has('user_id') && isset($_COOKIE['SHANFIX_REMEMBER'])) {
            try {
                Auth::loginFromCookie();
            } catch (\Throwable $e) {
                // A failure here must never block the request — worst case
                // the user signs in by hand.
                Logger::warning('Remember-me restore failed: ' . $e->getMessage());
            }
        }

        $this->securityHeaders();

        // Values every view can rely on.
        View::share('appName', Settings::get('company_name', Config::get('app.name', 'Shanfix Technology')));
        View::share('flashes', Session::pullFlash());

        return $this;
    }

    private function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 0');

        // All CSS/JS is served from our own origin; inline styles are used for
        // chart bar widths and avatar colours only.
        //
        // The nonce is always issued: the layout runs one short inline script
        // in <head> to apply the saved colour theme before anything paints,
        // and with app.inline_assets on the whole of app.js is inlined too.
        //
        // A nonce rather than 'unsafe-inline' — this still rejects any script
        // an attacker manages to inject, since they cannot guess the value.
        $scriptSrc = "'self' 'nonce-" . csp_nonce() . "'";

        header(
            "Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src {$scriptSrc}; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'"
        );
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $request = Request::capture();

        try {
            $this->dispatch($request);
        } catch (HttpException $e) {
            $this->renderError($e->getStatus(), $e->getMessage(), $request);
        } catch (\Throwable $e) {
            Logger::error($e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = Config::get('app.debug', false)
                ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                : 'An unexpected error occurred. The technical team has been notified.';

            $this->renderError(500, $message, $request);
        }
    }

    private function dispatch(Request $request): void
    {
        // Strip the sub-directory prefix so routes stay absolute.
        $uri  = parse_url($request->uri, PHP_URL_PATH) ?? '/';
        $base = base_path();

        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        $uri   = '/' . trim($uri, '/');
        $route = $this->router->match($request->method, $uri);

        if ($route === null) {
            throw new HttpException(404, 'The page you are looking for was not found.');
        }

        $request->setRouteParams($route['params']);

        foreach ($route['middleware'] as $middleware) {
            $this->runMiddleware($middleware, $request);
        }

        $handler = $route['handler'];

        if (is_callable($handler)) {
            $handler($request);
            return;
        }

        [$class, $method] = $handler;

        if (!class_exists($class) || !method_exists($class, $method)) {
            throw new \RuntimeException("Route handler {$class}::{$method} does not exist.");
        }

        (new $class())->{$method}($request);
    }

    private function runMiddleware(string $middleware, Request $request): void
    {
        // "permission:clients.manage"
        [$name, $arg] = array_pad(explode(':', $middleware, 2), 2, null);

        switch ($name) {
            case 'auth':
                if (!Auth::check()) {
                    if ($request->wantsJson()) {
                        Response::json(['ok' => false, 'error' => 'Not authenticated.'], 401);
                    }
                    Session::put('intended_url', $request->uri);
                    if (Session::has('_expired')) {
                        Session::forget('_expired');
                        Session::warning('Your session timed out. Please sign in again.');
                    }
                    Response::to('/login');
                }
                Auth::touchSeen();
                break;

            case 'guest':
                if (Auth::check()) {
                    Response::to('/dashboard');
                }
                break;

            case 'csrf':
                Csrf::verify($request);
                break;

            case 'permission':
                if ($arg === null) {
                    throw new \RuntimeException('permission middleware requires an argument.');
                }
                Auth::authorize($arg);
                break;

            default:
                throw new \RuntimeException("Unknown middleware: {$middleware}");
        }
    }

    private function renderError(int $status, string $message, Request $request): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }

        if ($request->wantsJson()) {
            Response::json(['ok' => false, 'error' => $message], $status);
        }

        try {
            View::render('errors/error', [
                'status'  => $status,
                'message' => $message,
            ], Auth::check() ? 'app' : 'blank');
        } catch (\Throwable) {
            // Last resort if even the error view fails (e.g. DB down).
            echo '<!doctype html><meta charset="utf-8">'
               . '<title>Error ' . $status . '</title>'
               . '<div style="font:16px/1.6 system-ui;max-width:640px;margin:80px auto;padding:0 24px;color:#0D2B4B">'
               . '<h1 style="margin:0 0 8px">Error ' . $status . '</h1>'
               . '<p style="color:#5A6B7D">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
               . '</div>';
        }
    }
}
