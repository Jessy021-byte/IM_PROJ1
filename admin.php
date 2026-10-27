<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/config.php';

function redirect_to_admin(): never
{
    header('Location: admin.php');
    exit;
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['demo_login', 'logout'], true)) {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('The form expired or could not be verified. Please return to the sign-in page and try again.');
    }

    if ($action === 'demo_login') {
        $email = $_POST['email'] ?? null;
        $password = $_POST['password'] ?? null;
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !is_string($password) || $password === '') {
            $_SESSION['login_error'] = 'Enter a valid email address and a non-empty password.';
            header('Location: login.php');
            exit;
        }

        unset($_SESSION['login_error']);
        session_regenerate_id(true);
        $_SESSION['demo_signed_in'] = true;
        $_SESSION['demo_email'] = $email;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: admin.php');
        exit;
    }

    unset($_SESSION['demo_signed_in'], $_SESSION['demo_email']);
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['demo_signed_in'])) {
    header('Location: login.php');
    exit;
}

$pdo = null;
$database_error = null;

try {
    $pdo = create_database_connection();
} catch (PDOException $exception) {
    error_log('Inventory database connection failed: ' . $exception->getMessage());
    $database_error = 'Could not connect to the database. Check that MySQL is running, '
        . 'schema.sql has been imported, and config.php has the correct connection settings.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'The form expired or could not be verified. Please try again.',
        ];
        redirect_to_admin();
    }

    try {
        if ($action === 'create' || $action === 'update') {
            $product_name = post_string('product_name');
            $sku = post_string('sku');
            $description = post_string('description');
            $category_id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            $price_input = post_string('price');
            $stock_input = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);

            if (
                $product_name === ''
                || $sku === ''
                || $description === ''
                || $category_id === false
                || $category_id === null
                || $category_id < 1
                || $category_id > 4294967295
                || preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $price_input) !== 1
                || $stock_input === false
                || $stock_input === null
                || $stock_input < 0
                || $stock_input > 4294967295
            ) {
                throw new InvalidArgumentException(
                    'Fill in every field. Price must have up to two decimal places, and price and stock must be within the supported range.'
                );
            }

            if ($action === 'create') {
                $statement = $pdo->prepare(
                    'INSERT INTO products '
                    . '(product_name, sku, description, category_id, price, stock_quantity) '
                    . 'VALUES (:product_name, :sku, :description, :category_id, :price, :stock_quantity)'
                );
                $statement->execute([
                    'product_name' => $product_name,
                    'sku' => $sku,
                    'description' => $description,
                    'category_id' => $category_id,
                    'price' => $price_input,
                    'stock_quantity' => $stock_input,
                ]);
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'Product added. The INSERT trigger wrote an activity log.',
                ];
            } else {
                $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                if ($product_id === false || $product_id === null || $product_id < 1 || $product_id > 4294967295) {
                    throw new InvalidArgumentException('The product to update is not valid.');
                }

                $statement = $pdo->prepare(
                    'UPDATE products SET '
                    . 'product_name = :product_name, sku = :sku, description = :description, '
                    . 'category_id = :category_id, price = :price, stock_quantity = :stock_quantity '
                    . 'WHERE product_id = :product_id'
                );
                $statement->execute([
                    'product_name' => $product_name,
                    'sku' => $sku,
                    'description' => $description,
                    'category_id' => $category_id,
                    'price' => $price_input,
                    'stock_quantity' => $stock_input,
                    'product_id' => $product_id,
                ]);
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM products WHERE product_id = :product_id');
                    $exists->execute(['product_id' => $product_id]);
                    $product_exists = $exists->fetchColumn() !== false;
                } else {
                    $product_exists = true;
                }

                $_SESSION['flash'] = $product_exists
                    ? [
                        'type' => 'success',
                        'message' => 'Product updated. A price change is recorded by the UPDATE trigger.',
                    ]
                    : ['type' => 'error', 'message' => 'No product was found to update.'];
            }
        } elseif ($action === 'delete') {
            $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            if ($product_id === false || $product_id === null || $product_id < 1 || $product_id > 4294967295) {
                throw new InvalidArgumentException('The product to delete is not valid.');
            }

            $pdo->beginTransaction();
            $lock_product = $pdo->prepare('SELECT product_id FROM products WHERE product_id = :product_id FOR UPDATE');
            $lock_product->execute(['product_id' => $product_id]);
            $lock_product->fetchColumn();

            $active_loans = $pdo->prepare(
                "SELECT COUNT(*) FROM customer_loans WHERE product_id = :product_id AND loan_status = 'active'"
            );
            $active_loans->execute(['product_id' => $product_id]);
            if ((int) $active_loans->fetchColumn() > 0) {
                throw new InvalidArgumentException('This product cannot be deleted while a customer still has it borrowed.');
            }

            $pending_orders = $pdo->prepare(
                "SELECT COUNT(*) FROM customer_orders "
                . "WHERE product_id = :product_id AND order_status = 'pending' AND stock_reserved = 1"
            );
            $pending_orders->execute(['product_id' => $product_id]);
            if ((int) $pending_orders->fetchColumn() > 0) {
                throw new InvalidArgumentException('This product cannot be deleted while a customer checkout is pending.');
            }

            $statement = $pdo->prepare('DELETE FROM products WHERE product_id = :product_id');
            $statement->execute(['product_id' => $product_id]);
            $pdo->commit();

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $statement->rowCount() > 0
                    ? 'Product deleted. The DELETE trigger saved it in the archive.'
                    : 'No product was found to delete.',
            ];
        } elseif ($action === 'return_loan') {
            $loan_id = filter_var($_POST['loan_id'] ?? null, FILTER_VALIDATE_INT);
            if ($loan_id === false || $loan_id === null || $loan_id < 1) {
                throw new InvalidArgumentException('The loan to return is not valid.');
            }

            $pdo->beginTransaction();
            $loan_query = $pdo->prepare(
                "SELECT product_id, quantity, loan_status FROM customer_loans WHERE loan_id = :loan_id FOR UPDATE"
            );
            $loan_query->execute(['loan_id' => $loan_id]);
            $loan = $loan_query->fetch();
            if (!$loan || $loan['loan_status'] !== 'active') {
                throw new InvalidArgumentException('This loan was already returned or does not exist.');
            }
            if ($loan['product_id'] === null) {
                throw new InvalidArgumentException('The product was removed from inventory; contact the administrator before closing this loan.');
            }

            $restore = $pdo->prepare(
                'UPDATE products SET stock_quantity = stock_quantity + :quantity WHERE product_id = :product_id'
            );
            $restore->execute(['quantity' => $loan['quantity'], 'product_id' => $loan['product_id']]);
            if ($restore->rowCount() !== 1) {
                throw new InvalidArgumentException('The product could not be restored to inventory.');
            }

            $mark_returned = $pdo->prepare(
                "UPDATE customer_loans SET loan_status = 'returned', returned_at = CURRENT_TIMESTAMP "
                . "WHERE loan_id = :loan_id AND loan_status = 'active'"
            );
            $mark_returned->execute(['loan_id' => $loan_id]);
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Returned items were added back to available stock.'];
        } else {
            throw new InvalidArgumentException('Unknown form action.');
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Inventory database operation failed: ' . $exception->getMessage());
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'The database rejected that operation. Check the SKU is unique and the selected category exists.',
        ];
    }

    redirect_to_admin();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$categories = [];
$products = [];
$activity_logs = [];
$price_history = [];
$deleted_products = [];
$customer_orders = [];
$customer_loans = [];

if ($pdo instanceof PDO) {
    try {
        $categories = $pdo->query(
            'SELECT category_id, category_name FROM categories ORDER BY category_name'
        )->fetchAll();

        $products = $pdo->query(
            'SELECT p.product_id, p.product_name, p.sku, p.description, p.category_id, '
            . 'c.category_name, p.price, p.stock_quantity, p.created_at '
            . 'FROM products AS p '
            . 'JOIN categories AS c ON c.category_id = p.category_id '
            . 'ORDER BY p.product_id DESC'
        )->fetchAll();

        $activity_logs = $pdo->query(
            'SELECT product_id, action_type, action_description, created_at '
            . 'FROM activity_logs ORDER BY log_id DESC LIMIT 10'
        )->fetchAll();

        $price_history = $pdo->query(
            'SELECT product_name, sku, old_price, new_price, changed_at '
            . 'FROM price_history ORDER BY history_id DESC LIMIT 10'
        )->fetchAll();

        $deleted_products = $pdo->query(
            'SELECT original_product_id, product_name, sku, category_name, price, deleted_at '
            . 'FROM deleted_products ORDER BY archive_id DESC LIMIT 10'
        )->fetchAll();

        $customer_orders = $pdo->query(
            'SELECT order_id, product_name, sku, customer_name, customer_email, quantity, unit_price, '
            . 'order_status, created_at FROM customer_orders ORDER BY order_id DESC LIMIT 50'
        )->fetchAll();

        $customer_loans = $pdo->query(
            'SELECT loan_id, product_name, sku, customer_name, customer_email, quantity, due_date, '
            . 'loan_status, created_at, returned_at FROM customer_loans ORDER BY loan_id DESC LIMIT 50'
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log('Inventory data loading failed: ' . $exception->getMessage());
        $database_error = 'Could not load inventory data. Check that schema.sql has been imported correctly.';
        $categories = [];
        $products = [];
        $activity_logs = [];
        $price_history = [];
        $deleted_products = [];
        $customer_orders = [];
        $customer_loans = [];
    }
}

require __DIR__ . '/template.php';
