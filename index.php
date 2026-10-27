<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/config.php';
require __DIR__ . '/stripe.php';

function redirect_to_store(): never
{
    header('Location: index.php');
    exit;
}

$flash = $_SESSION['customer_flash'] ?? null;
unset($_SESSION['customer_flash']);
$database_error = null;
$products = [];
$pdo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('The form expired or could not be verified. Reload the page and try again.');
    }

    try {
        $pdo = create_database_connection();
        $action = post_string('action');
        $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $customer_name = post_string('customer_name');
        $customer_email = post_string('customer_email');

        if (
            $product_id === false || $product_id === null || $product_id < 1
            || $quantity === false || $quantity === null || $quantity < 1 || $quantity > 1000
            || $customer_name === '' || preg_match('/\A.{1,150}\z/us', $customer_name) !== 1
            || !filter_var($customer_email, FILTER_VALIDATE_EMAIL) || strlen($customer_email) > 254
        ) {
            throw new InvalidArgumentException('Enter your name, a valid email address, and a quantity from 1 to 1,000.');
        }

        if ($action === 'borrow') {
            $due_date = post_string('due_date');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due_date);
            $today = new DateTimeImmutable('today');
            if (
                $date === false
                || $date->format('Y-m-d') !== $due_date
                || $date <= $today
                || $date > $today->modify('+1 year')
            ) {
                throw new InvalidArgumentException('Choose a return date within the next year.');
            }

            $pdo->beginTransaction();
            $product_query = $pdo->prepare(
                'SELECT product_id, product_name, sku, stock_quantity FROM products '
                . 'WHERE product_id = :product_id FOR UPDATE'
            );
            $product_query->execute(['product_id' => $product_id]);
            $product = $product_query->fetch();
            if (!$product || (int) $product['stock_quantity'] < $quantity) {
                throw new InvalidArgumentException('That quantity is not currently available to borrow.');
            }

            $deduct = $pdo->prepare(
                'UPDATE products SET stock_quantity = stock_quantity - :quantity '
                . 'WHERE product_id = :product_id AND stock_quantity >= :available_quantity'
            );
            $deduct->execute([
                'quantity' => $quantity,
                'product_id' => $product_id,
                'available_quantity' => $quantity,
            ]);
            if ($deduct->rowCount() !== 1) {
                throw new InvalidArgumentException('That quantity is no longer available to borrow.');
            }

            $loan = $pdo->prepare(
                'INSERT INTO customer_loans '
                . '(product_id, product_name, sku, customer_name, customer_email, quantity, due_date) '
                . 'VALUES (:product_id, :product_name, :sku, :customer_name, :customer_email, :quantity, :due_date)'
            );
            $loan->execute([
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'sku' => $product['sku'],
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'quantity' => $quantity,
                'due_date' => $due_date,
            ]);
            $pdo->commit();
            $_SESSION['customer_flash'] = [
                'type' => 'success',
                'message' => 'Borrowing confirmed. Please return the item by ' . $due_date . '.',
            ];
        } elseif ($action === 'buy') {
            $pdo->beginTransaction();
            $product_query = $pdo->prepare(
                'SELECT product_id, product_name, sku, price, stock_quantity FROM products '
                . 'WHERE product_id = :product_id FOR UPDATE'
            );
            $product_query->execute(['product_id' => $product_id]);
            $product = $product_query->fetch();
            if (!$product || (int) $product['stock_quantity'] < $quantity) {
                throw new InvalidArgumentException('That quantity is not currently available to buy.');
            }

            $deduct = $pdo->prepare(
                'UPDATE products SET stock_quantity = stock_quantity - :quantity '
                . 'WHERE product_id = :product_id AND stock_quantity >= :available_quantity'
            );
            $deduct->execute([
                'quantity' => $quantity,
                'product_id' => $product_id,
                'available_quantity' => $quantity,
            ]);
            if ($deduct->rowCount() !== 1) {
                throw new InvalidArgumentException('That quantity is no longer available to buy.');
            }

            $order = $pdo->prepare(
                'INSERT INTO customer_orders '
                . '(product_id, product_name, sku, customer_name, customer_email, quantity, unit_price) '
                . 'VALUES (:product_id, :product_name, :sku, :customer_name, :customer_email, :quantity, :unit_price)'
            );
            $order->execute([
                'product_id' => $product_id,
                'product_name' => $product['product_name'],
                'sku' => $product['sku'],
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'quantity' => $quantity,
                'unit_price' => $product['price'],
            ]);
            $order_id = (int) $pdo->lastInsertId();
            $pdo->commit();

            try {
                $checkout = stripe_create_checkout_session(
                    $order_id,
                    $product['product_name'],
                    $customer_email,
                    $quantity,
                    $product['price']
                );
                $save_checkout = $pdo->prepare(
                    'UPDATE customer_orders SET checkout_session_id = :session_id WHERE order_id = :order_id'
                );
                $save_checkout->execute([
                    'session_id' => $checkout['id'],
                    'order_id' => $order_id,
                ]);
                header('Location: ' . $checkout['url'], true, 303);
                exit;
            } catch (RuntimeException | PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                try {
                    $pdo->beginTransaction();
                    $release = $pdo->prepare(
                        'UPDATE products SET stock_quantity = stock_quantity + :quantity '
                        . 'WHERE product_id = :product_id'
                    );
                    $release->execute(['quantity' => $quantity, 'product_id' => $product_id]);
                    $fail_order = $pdo->prepare(
                        "UPDATE customer_orders SET order_status = 'failed', stock_reserved = 0 "
                        . "WHERE order_id = :order_id AND order_status = 'pending'"
                    );
                    $fail_order->execute(['order_id' => $order_id]);
                    $pdo->commit();
                } catch (PDOException $release_exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Could not release stock after Stripe checkout failed: ' . $release_exception->getMessage());
                }
                throw new RuntimeException('Checkout could not be started and your stock reservation was released. Please try again.');
            }
        } else {
            throw new InvalidArgumentException('Unknown customer action.');
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['customer_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
    } catch (PDOException $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Customer order operation failed: ' . $exception->getMessage());
        $_SESSION['customer_flash'] = [
            'type' => 'error',
            'message' => 'We could not complete that request. Check that the database is running and try again.',
        ];
    } catch (RuntimeException $exception) {
        $_SESSION['customer_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
    }

    redirect_to_store();
}

try {
    $pdo = create_database_connection();
    $products = $pdo->query(
        'SELECT p.product_id, p.product_name, p.sku, p.description, p.price, p.stock_quantity, '
        . 'c.category_name FROM products AS p '
        . 'JOIN categories AS c ON c.category_id = p.category_id '
        . 'ORDER BY p.product_name'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('Customer catalog loading failed: ' . $exception->getMessage());
    $database_error = 'The catalog is temporarily unavailable. Please try again later.';
}

$currency = 'USD';
$checkout_message = null;
if (($_GET['checkout'] ?? null) === 'success' && is_string($_GET['session_id'] ?? null)) {
    if ($pdo instanceof PDO && $database_error === null) {
        $statement = $pdo->prepare(
            'SELECT order_status FROM customer_orders WHERE checkout_session_id = :session_id'
        );
        $statement->execute(['session_id' => $_GET['session_id']]);
        $order_status = $statement->fetchColumn();
        $checkout_message = $order_status === 'paid'
            ? 'Payment confirmed. Thank you for your purchase.'
            : 'Checkout returned. Payment confirmation will appear here once Stripe confirms it.';
    }
} elseif (($_GET['checkout'] ?? null) === 'cancelled') {
    $checkout_message = 'Checkout was cancelled. Any reserved stock will be restored when Stripe expires the checkout session.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JLP Store</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<header>
    <div>
        <h1>JLP Store</h1>
        <p>Browse products to buy or borrow.</p>
    </div>
    <a class="header-link" href="login.php">Admin sign in</a>
</header>
<main>
    <p class="intro">Purchases use Stripe Checkout in test mode. Borrowing confirms immediately when stock is available; return items by the date you choose.</p>

    <?php if ($database_error !== null): ?>
        <div class="notice error" role="alert"><?= escape_html($database_error) ?></div>
    <?php endif; ?>
    <?php if (is_array($flash)): ?>
        <div class="notice <?= escape_html($flash['type'] ?? 'error') ?>" role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>">
            <?= escape_html($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>
    <?php if ($checkout_message !== null): ?>
        <div class="notice success" role="status"><?= escape_html($checkout_message) ?></div>
    <?php endif; ?>

    <section class="panel" aria-labelledby="catalog-heading">
        <div class="panel-heading">
            <h2 id="catalog-heading">Products</h2>
            <p>Prices shown in <?= escape_html($currency) ?></p>
        </div>
        <?php if ($products === []): ?>
            <p class="empty"><?= $database_error === null ? 'No products are available yet.' : 'Products will appear here when the catalog is available.' ?></p>
        <?php else: ?>
            <div class="product-list">
                <?php foreach ($products as $product): ?>
                    <article class="product-card">
                        <h3><?= escape_html($product['product_name']) ?></h3>
                        <p class="product-meta">
                            <?= escape_html($product['category_name']) ?> · SKU <?= escape_html($product['sku']) ?>
                        </p>
                        <p><?= escape_html($product['description']) ?></p>
                        <p class="store-price"><?= escape_html($currency) ?> <?= escape_html(number_format((float) $product['price'], 2)) ?></p>
                        <p class="product-meta"><?= escape_html($product['stock_quantity']) ?> available</p>
                        <?php if ((int) $product['stock_quantity'] > 0): ?>
                            <form method="post" class="store-order-form">
                                <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="buy">
                                <input type="hidden" name="product_id" value="<?= escape_html($product['product_id']) ?>">
                                <h4>Buy</h4>
                                <div class="field">
                                    <label for="buy-name-<?= escape_html($product['product_id']) ?>">Your name</label>
                                    <input id="buy-name-<?= escape_html($product['product_id']) ?>" name="customer_name" maxlength="150" required>
                                </div>
                                <div class="field">
                                    <label for="buy-email-<?= escape_html($product['product_id']) ?>">Email</label>
                                    <input id="buy-email-<?= escape_html($product['product_id']) ?>" name="customer_email" type="email" maxlength="254" required>
                                </div>
                                <div class="field">
                                    <label for="buy-qty-<?= escape_html($product['product_id']) ?>">Quantity</label>
                                    <input id="buy-qty-<?= escape_html($product['product_id']) ?>" name="quantity" type="number" min="1" max="<?= escape_html($product['stock_quantity']) ?>" value="1" required>
                                </div>
                                <button class="primary" type="submit">Buy with card</button>
                            </form>
                            <form method="post" class="store-order-form">
                                <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="borrow">
                                <input type="hidden" name="product_id" value="<?= escape_html($product['product_id']) ?>">
                                <h4>Borrow</h4>
                                <div class="field">
                                    <label for="borrow-name-<?= escape_html($product['product_id']) ?>">Your name</label>
                                    <input id="borrow-name-<?= escape_html($product['product_id']) ?>" name="customer_name" maxlength="150" required>
                                </div>
                                <div class="field">
                                    <label for="borrow-email-<?= escape_html($product['product_id']) ?>">Email</label>
                                    <input id="borrow-email-<?= escape_html($product['product_id']) ?>" name="customer_email" type="email" maxlength="254" required>
                                </div>
                                <div class="field">
                                    <label for="borrow-qty-<?= escape_html($product['product_id']) ?>">Quantity</label>
                                    <input id="borrow-qty-<?= escape_html($product['product_id']) ?>" name="quantity" type="number" min="1" max="<?= escape_html($product['stock_quantity']) ?>" value="1" required>
                                </div>
                                <div class="field">
                                    <label for="due-<?= escape_html($product['product_id']) ?>">Return by</label>
                                    <input id="due-<?= escape_html($product['product_id']) ?>" name="due_date" type="date" min="<?= escape_html((new DateTimeImmutable('tomorrow'))->format('Y-m-d')) ?>" max="<?= escape_html((new DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d')) ?>" required>
                                </div>
                                <button class="secondary" type="submit">Borrow now</button>
                            </form>
                        <?php else: ?>
                            <p class="empty">Currently out of stock.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
