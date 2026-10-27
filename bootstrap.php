<?php
declare(strict_types=1);

$cookie_params = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookie_params['lifetime'],
    'path' => $cookie_params['path'],
    'domain' => $cookie_params['domain'],
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape_html(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_valid_csrf_token(mixed $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? null;
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid form submission.');
    }

    return trim($value);
}
