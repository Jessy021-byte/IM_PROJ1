<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$webhook_secret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';
$signature_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$payload = file_get_contents('php://input');
if ($webhook_secret === '' || !is_string($payload) || $signature_header === '') {
    http_response_code(400);
    exit('Webhook is not configured.');
}

$timestamp = null;
$signatures = [];
foreach (explode(',', $signature_header) as $component) {
    [$key, $value] = array_pad(explode('=', trim($component), 2), 2, '');
    if ($key === 't' && ctype_digit($value)) {
        $timestamp = (int) $value;
    } elseif ($key === 'v1' && $value !== '') {
        $signatures[] = $value;
    }
}

if ($timestamp === null || abs(time() - $timestamp) > 300 || $signatures === []) {
    http_response_code(400);
    exit('Invalid Stripe signature.');
}

$expected_signature = hash_hmac('sha256', $timestamp . '.' . $payload, $webhook_secret);
$signature_valid = false;
foreach ($signatures as $signature) {
    if (hash_equals($expected_signature, $signature)) {
        $signature_valid = true;
    }
}
if (!$signature_valid) {
    http_response_code(400);
    exit('Invalid Stripe signature.');
}

$event = json_decode($payload, true);
if (!is_array($event) || !isset($event['id'], $event['type'], $event['data']['object'])) {
    http_response_code(400);
    exit('Invalid Stripe event.');
}

try {
    $pdo = create_database_connection();
    $pdo->beginTransaction();

    $record_event = $pdo->prepare(
        'INSERT IGNORE INTO stripe_webhook_events (event_id, event_type) VALUES (:event_id, :event_type)'
    );
    $record_event->execute([
        'event_id' => $event['id'],
        'event_type' => $event['type'],
    ]);
    if ($record_event->rowCount() === 0) {
        $pdo->commit();
        http_response_code(200);
        exit('Event already processed.');
    }

    $event_type = $event['type'];
    $checkout = $event['data']['object'];
    $order_id = filter_var($checkout['metadata']['order_id'] ?? null, FILTER_VALIDATE_INT);
    $session_id = $checkout['id'] ?? null;
    if (
        is_int($order_id) && $order_id > 0
        && is_string($session_id) && $session_id !== ''
    ) {
        $order_query = $pdo->prepare(
            'SELECT product_id, quantity, order_status, stock_reserved FROM customer_orders '
            . 'WHERE order_id = :order_id AND checkout_session_id = :session_id FOR UPDATE'
        );
        $order_query->execute(['order_id' => $order_id, 'session_id' => $session_id]);
        $order = $order_query->fetch();

        if ($order && $order['order_status'] === 'pending') {
            $paid = in_array($event_type, [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
            ], true) && ($checkout['payment_status'] ?? null) === 'paid';

            if ($paid) {
                $mark_paid = $pdo->prepare(
                    "UPDATE customer_orders SET order_status = 'paid', stock_reserved = 0, "
                    . 'paid_at = CURRENT_TIMESTAMP WHERE order_id = :order_id AND order_status = \'pending\''
                );
                $mark_paid->execute(['order_id' => $order_id]);
            } elseif (
                $event_type === 'checkout.session.expired'
                && (int) $order['stock_reserved'] === 1
            ) {
                if ($order['product_id'] !== null) {
                    $restore = $pdo->prepare(
                        'UPDATE products SET stock_quantity = stock_quantity + :quantity '
                        . 'WHERE product_id = :product_id'
                    );
                    $restore->execute([
                        'quantity' => $order['quantity'],
                        'product_id' => $order['product_id'],
                    ]);
                }

                $cancel = $pdo->prepare(
                    "UPDATE customer_orders SET order_status = 'cancelled', stock_reserved = 0 "
                    . "WHERE order_id = :order_id AND order_status = 'pending'"
                );
                $cancel->execute(['order_id' => $order_id]);
            }
        }
    }

    $pdo->commit();
    http_response_code(200);
    echo 'OK';
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Stripe webhook processing failed: ' . $exception->getMessage());
    http_response_code(500);
    echo 'Webhook processing failed.';
}
