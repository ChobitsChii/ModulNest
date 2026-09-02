<?php

declare(strict_types=1);

namespace Modulon\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    private static bool $registered = false;
    private static bool $handling = false;
    private static string $basePath = '';
    private static string $appEnv = 'production';
    private static bool $debug = false;

    public static function register(string $basePath, string $appEnv, bool $debug): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        self::$basePath = rtrim($basePath, '/');
        self::$appEnv = strtolower(trim($appEnv)) !== '' ? strtolower(trim($appEnv)) : 'production';
        self::$debug = $debug;

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler([self::class, 'handlePhpError']);
        set_exception_handler([self::class, 'handleThrowable']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handlePhpError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            self::logPhpIssue($severity, $message, $file, $line);
            return true;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleThrowable(Throwable $throwable): void
    {
        if (self::$handling) {
            self::emitPlain500();
            return;
        }

        self::$handling = true;
        self::logThrowable($throwable);
        self::emitThrowableResponse($throwable);
        self::$handling = false;
    }

    public static function handleShutdown(): void
    {
        $lastError = error_get_last();
        if (!is_array($lastError)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        $type = (int) ($lastError['type'] ?? 0);
        if (!in_array($type, $fatalTypes, true)) {
            return;
        }

        $message = (string) ($lastError['message'] ?? 'Fatal error');
        $file = (string) ($lastError['file'] ?? 'unknown');
        $line = (int) ($lastError['line'] ?? 0);
        self::handleThrowable(new ErrorException($message, 0, $type, $file, $line));
    }

    private static function emitThrowableResponse(Throwable $throwable): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            SecurityHeaders::apply();
            header('Content-Type: text/html; charset=UTF-8');
        }

        if (self::$debug) {
            echo self::renderDebugHtml($throwable);
            return;
        }

        echo self::renderGenericHtml();
    }

    private static function emitPlain500(): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            SecurityHeaders::apply();
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo 'Internal Server Error';
    }

    private static function renderDebugHtml(Throwable $throwable): string
    {
        $context = self::requestContext();
        $trace = htmlspecialchars($throwable->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($throwable->getMessage(), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars($throwable::class, ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($throwable->getFile(), ENT_QUOTES, 'UTF-8');
        $line = (int) $throwable->getLine();
        $method = htmlspecialchars((string) ($context['method'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $uri = htmlspecialchars((string) ($context['uri'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $timestamp = htmlspecialchars((string) ($context['timestamp'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $userId = htmlspecialchars((string) ($context['user_id'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $requestPayload = htmlspecialchars((string) json_encode($context['request'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modulon Debug Error</title>
    <style>
        :root { color-scheme: dark; }
        body { margin:0; font-family: ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial; background:#0f172a; color:#e2e8f0; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
        .card { background:#111b2e; border:1px solid #334155; border-radius:10px; margin-bottom:16px; overflow:hidden; }
        .head { padding:10px 14px; font-weight:700; font-size:14px; background:#1e293b; border-bottom:1px solid #334155; }
        .body { padding:14px; }
        h1 { margin:0 0 14px; font-size:24px; }
        dl { margin:0; display:grid; grid-template-columns:180px 1fr; gap:8px 12px; }
        dt { color:#94a3b8; font-weight:600; }
        dd { margin:0; }
        pre { margin:0; overflow:auto; white-space:pre-wrap; line-height:1.45; }
        .error { color:#fca5a5; font-weight:700; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Uncaught Exception</h1>
        <div class="card">
            <div class="head">Error</div>
            <div class="body">
                <dl>
                    <dt>Type</dt><dd class="error">{$type}</dd>
                    <dt>Message</dt><dd>{$message}</dd>
                    <dt>File</dt><dd>{$file}</dd>
                    <dt>Line</dt><dd>{$line}</dd>
                    <dt>Method</dt><dd>{$method}</dd>
                    <dt>URI</dt><dd>{$uri}</dd>
                    <dt>User ID</dt><dd>{$userId}</dd>
                    <dt>Timestamp</dt><dd>{$timestamp}</dd>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="head">Request Data</div>
            <div class="body"><pre>{$requestPayload}</pre></div>
        </div>
        <div class="card">
            <div class="head">Stack Trace</div>
            <div class="body"><pre>{$trace}</pre></div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private static function renderGenericHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>500 Internal Server Error</title>
    <style>
        body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center}
        .box{max-width:560px;padding:28px;border:1px solid #334155;border-radius:12px;background:#111b2e;text-align:center}
        h1{margin:0 0 8px;font-size:30px}
        p{margin:0;color:#94a3b8}
    </style>
</head>
<body>
    <div class="box">
        <h1>500</h1>
        <p>Es ist ein interner Fehler aufgetreten.</p>
    </div>
</body>
</html>
HTML;
    }

    private static function logThrowable(Throwable $throwable): void
    {
        $context = self::requestContext();
        $record = [
            'timestamp' => $context['timestamp'] ?? date('c'),
            'env' => self::$appEnv,
            'debug' => self::$debug,
            'type' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'method' => $context['method'] ?? null,
            'uri' => $context['uri'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'request' => $context['request'] ?? [],
            'trace' => $throwable->getTraceAsString(),
        ];

        (new RotatingFileLogger(self::$basePath))->write('modulon', $record);
    }

    private static function logPhpIssue(int $severity, string $message, string $file, int $line): void
    {
        $context = self::requestContext();
        $record = [
            'timestamp' => $context['timestamp'] ?? date('c'),
            'env' => self::$appEnv,
            'debug' => self::$debug,
            'type' => 'php_issue',
            'severity' => $severity,
            'severity_name' => self::severityName($severity),
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'method' => $context['method'] ?? null,
            'uri' => $context['uri'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'request' => $context['request'] ?? [],
        ];

        (new RotatingFileLogger(self::$basePath))->write('modulon', $record);
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestContext(): array
    {
        $request = [
            'get' => self::sanitize($_GET ?? []),
            'post' => self::sanitize($_POST ?? []),
            'cookie' => self::sanitize($_COOKIE ?? []),
        ];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $request['session_keys'] = array_keys($_SESSION);
        }

        $userId = null;
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['auth_user_id'])) {
            $userId = (int) $_SESSION['auth_user_id'];
        }

        return [
            'timestamp' => date('c'),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri' => self::sanitizeUri((string) ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''))),
            'user_id' => $userId,
            'request' => $request,
        ];
    }

    private static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = self::sanitize($childValue, is_string($childKey) ? $childKey : null);
            }
            return $sanitized;
        }

        if (!is_scalar($value) && $value !== null) {
            return '[non-scalar]';
        }

        if ($key !== null && self::isSensitiveKey($key)) {
            return '***';
        }

        if (is_string($value) && mb_strlen($value) > 2000) {
            return mb_substr($value, 0, 2000) . '...[truncated]';
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'cookie', 'key', 'session', 'phpsessid', 'sid', 'csrf', 'totp', 'recovery', 'remember', 'code'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function sanitizeUri(string $uri): string
    {
        return preg_replace_callback(
            '/([?&])([^=&]+)=([^&]*)/',
            static function (array $matches): string {
                $key = urldecode((string) ($matches[2] ?? ''));
                if (self::isSensitiveKey($key)) {
                    return (string) $matches[1] . (string) $matches[2] . '=***';
                }

                return (string) $matches[0];
            },
            $uri,
        ) ?? $uri;
    }

    private static function severityName(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }
}
