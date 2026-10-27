<?php
declare(strict_types=1);

function stripe_currency(): string
{
    return 'usd';
}

function stripe_base_url(): string
{
    $configured_url = getenv('APP_BASE_URL');
    if ($configured_url !== false && $configured_url !== '') {
        $parts = parse_url($configured_url);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('APP_BASE_URL must be an absolute HTTP or HTTPS URL without a query string.');
        }

        return rtrim($configured_url, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' || preg_match('/\A[a-zA-Z0-9.:[\]-]+\z/', $host) !== 1) {
        throw new RuntimeException('Set APP_BASE_URL to the public address of this app.');
    }
    if (preg_match('/\A(?:localhost|127\.0\.0\.1)(?::\d{1,5})?\z/i', $host) !== 1) {
        throw new RuntimeException('Set APP_BASE_URL explicitly before enabling Stripe Checkout on a public host.');
    }

    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $path = $directory === '/' || $directory === '.' ? '' : '/' . trim($directory, '/');

    return $scheme . '://' . $host . $path;
}

function stripe_amount_in_cents(string $price): int
{
    if (preg_match('/\A(\d{1,8})(?:\.(\d{1,2}))?\z/', $price, $matches) !== 1) {
        throw new RuntimeException('The product price is not valid for checkout.');
    }

    $whole = (int) $matches[1];
    $fraction = (int) str_pad($matches[2] ?? '', 2, '0');

    $amount = $whole * 100 + $fraction;
    if ($amount < 50) {
        throw new RuntimeException('Stripe Checkout requires a product price of at least USD 0.50.');
    }

    return $amount;
}

function stripe_create_checkout_session(
    int $order_id,
    string $product_name,
    string $customer_email,
    int $quantity,
    string $price
): array {
    $secret_key = getenv('STRIPE_SECRET_KEY') ?: '';
    if (!str_starts_with($secret_key, 'sk_test_')) {
        throw new RuntimeException('Stripe test checkout is not configured. Set STRIPE_SECRET_KEY to a Stripe test secret key.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Stripe checkout.');
    }

    $base_url = stripe_base_url();
    $parameters = [
        'mode' => 'payment',
        'payment_method_types[0]' => 'card',
        'customer_email' => $customer_email,
        'client_reference_id' => (string) $order_id,
        'metadata[order_id]' => (string) $order_id,
        'line_items[0][quantity]' => (string) $quantity,
        'line_items[0][price_data][currency]' => stripe_currency(),
        'line_items[0][price_data][unit_amount]' => (string) stripe_amount_in_cents($price),
        'line_items[0][price_data][product_data][name]' => $product_name,
        'success_url' => $base_url . '/index.php?checkout=success&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $base_url . '/index.php?checkout=cancelled',
    ];

    $curl = curl_init('https://api.stripe.com/v1/checkout/sessions');
    if ($curl === false) {
        throw new RuntimeException('Could not initialize Stripe checkout.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($parameters),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secret_key,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curl_error = curl_error($curl);
    curl_close($curl);

    if (!is_string($response)) {
        error_log('Stripe checkout request failed: ' . $curl_error);
        throw new RuntimeException('Stripe could not start checkout. Please try again later.');
    }

    $result = json_decode($response, true);
    if ($status < 200 || $status >= 300 || !is_array($result) || !isset($result['id'], $result['url'])) {
        error_log('Stripe checkout returned HTTP ' . $status . '.');
        throw new RuntimeException('Stripe could not start checkout. Check the test key and try again.');
    }

    return $result;
}
