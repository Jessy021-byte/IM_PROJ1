<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JLP Inventory Desk | Admin</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<header>
    <div>
        <h1>JLP Admin Desk</h1>
        <p>Manage inventory, customer orders, and borrowed items.</p>
    </div>
    <nav class="header-actions" aria-label="Admin navigation">
        <a class="header-link" href="index.php">Customer store</a>
        <form method="post" action="admin.php" class="header-logout">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token']) ?>">
        <button class="header-link" type="submit">Sign out</button>
        </form>
    </nav>
</header>
<main>
    <p class="intro">Manage products and customer activity below. The database, not the PHP form handler, writes the activity log, price history, and deleted-product archive.</p>

    <?php if ($database_error !== null): ?>
        <div class="notice error"><?= escape_html($database_error) ?></div>
    <?php endif; ?>

    <?php if (is_array($flash)): ?>
        <div class="notice <?= escape_html($flash['type'] ?? 'error') ?>">
            <?= escape_html($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if ($pdo instanceof PDO): ?>
        <section class="panel" aria-labelledby="trigger-heading">
            <div class="panel-heading">
                <h2 id="trigger-heading">What the database automates</h2>
                <p>Defined in <code>schema.sql</code></p>
            </div>
            <div class="trigger-grid">
                <article class="trigger-card">
                    <h3>INSERT — activity log</h3>
                    <p>Adding a product creates an <code>activity_logs</code> row automatically.</p>
                </article>
                <article class="trigger-card">
                    <h3>UPDATE — price history</h3>
                    <p>Changing a product price saves both prices to <code>price_history</code>.</p>
                </article>
                <article class="trigger-card">
                    <h3>DELETE — archive</h3>
                    <p>Deleting a product copies its details to <code>deleted_products</code> first.</p>
                </article>
            </div>
        </section>

        <section class="panel" aria-labelledby="add-heading">
            <div class="panel-heading">
                <h2 id="add-heading">Add a product</h2>
                <p>Creating a product demonstrates the INSERT trigger.</p>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="field">
                        <label for="new-product-name">Product name</label>
                        <input id="new-product-name" name="product_name" maxlength="150" required>
                    </div>
                    <div class="field">
                        <label for="new-sku">SKU</label>
                        <input id="new-sku" name="sku" maxlength="50" required>
                    </div>
                    <div class="field">
                        <label for="new-category">Category</label>
                        <select id="new-category" name="category_id" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= escape_html($category['category_id']) ?>">
                                    <?= escape_html($category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="new-price">Price</label>
                        <input id="new-price" name="price" type="number" min="0" step="0.01" required>
                    </div>
                    <div class="field">
                        <label for="new-stock">Stock quantity</label>
                        <input id="new-stock" name="stock_quantity" type="number" min="0" step="1" required>
                    </div>
                    <div class="field full">
                        <label for="new-description">Description</label>
                        <textarea id="new-description" name="description" required></textarea>
                    </div>
                </div>
                <div class="actions"><button class="primary" type="submit">Add product</button></div>
            </form>
        </section>

        <section class="panel" aria-labelledby="products-heading">
            <div class="panel-heading">
                <h2 id="products-heading">Products (<?= count($products) ?>)</h2>
                <p>Edit a product to demonstrate UPDATE; use Delete to demonstrate DELETE.</p>
            </div>
            <?php if ($products === []): ?>
                <p class="empty">No products yet. Add one using the form above.</p>
            <?php else: ?>
                <div class="product-list">
                    <?php foreach ($products as $product): ?>
                        <article class="product-card">
                            <h3><?= escape_html($product['product_name']) ?></h3>
                            <p class="product-meta">
                                <?= escape_html($product['sku']) ?> ·
                                <?= escape_html($product['category_name']) ?> ·
                                Added <?= escape_html($product['created_at']) ?>
                            </p>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?= escape_html($product['product_id']) ?>">
                                <div class="edit-grid">
                                    <div class="field">
                                        <label for="name-<?= escape_html($product['product_id']) ?>">Product name</label>
                                        <input id="name-<?= escape_html($product['product_id']) ?>" name="product_name" maxlength="150" value="<?= escape_html($product['product_name']) ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="sku-<?= escape_html($product['product_id']) ?>">SKU</label>
                                        <input id="sku-<?= escape_html($product['product_id']) ?>" name="sku" maxlength="50" value="<?= escape_html($product['sku']) ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="category-<?= escape_html($product['product_id']) ?>">Category</label>
                                        <select id="category-<?= escape_html($product['product_id']) ?>" name="category_id" required>
                                            <?php foreach ($categories as $category): ?>
                                                <option
                                                    value="<?= escape_html($category['category_id']) ?>"
                                                    <?= (int) $category['category_id'] === (int) $product['category_id'] ? 'selected' : '' ?>
                                                ><?= escape_html($category['category_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label for="price-<?= escape_html($product['product_id']) ?>">Price</label>
                                        <input id="price-<?= escape_html($product['product_id']) ?>" name="price" type="number" min="0" step="0.01" value="<?= escape_html($product['price']) ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="stock-<?= escape_html($product['product_id']) ?>">Stock quantity</label>
                                        <input id="stock-<?= escape_html($product['product_id']) ?>" name="stock_quantity" type="number" min="0" step="1" value="<?= escape_html($product['stock_quantity']) ?>" required>
                                    </div>
                                    <div class="field full">
                                        <label for="description-<?= escape_html($product['product_id']) ?>">Description</label>
                                        <textarea id="description-<?= escape_html($product['product_id']) ?>" name="description" required><?= escape_html($product['description']) ?></textarea>
                                    </div>
                                </div>
                                <div class="actions"><button class="primary" type="submit">Save changes</button></div>
                            </form>
                            <form method="post" onsubmit="return confirm('Delete this product? Its details will be archived by the database trigger.');">
                                <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="product_id" value="<?= escape_html($product['product_id']) ?>">
                                <div class="actions"><button class="danger" type="submit">Delete product</button></div>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="activity-heading">
            <div class="panel-heading">
                <h2 id="activity-heading">Activity log</h2>
                <p>Written by the INSERT trigger</p>
            </div>
            <?php if ($activity_logs === []): ?>
                <p class="empty">No product insertions have been logged yet.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Product ID</th><th>Action</th><th>Details</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($activity_logs as $log): ?>
                        <tr>
                            <td><?= escape_html($log['product_id']) ?></td>
                            <td><?= escape_html($log['action_type']) ?></td>
                            <td><?= escape_html($log['action_description']) ?></td>
                            <td><?= escape_html($log['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="history-heading">
            <div class="panel-heading">
                <h2 id="history-heading">Price history</h2>
                <p>Written by the UPDATE trigger when the price changes</p>
            </div>
            <?php if ($price_history === []): ?>
                <p class="empty">No price changes have been recorded yet.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Product</th><th>SKU</th><th>Old price</th><th>New price</th><th>Changed</th></tr></thead>
                    <tbody>
                    <?php foreach ($price_history as $entry): ?>
                        <tr>
                            <td><?= escape_html($entry['product_name']) ?></td>
                            <td><?= escape_html($entry['sku']) ?></td>
                            <td><?= escape_html($entry['old_price']) ?></td>
                            <td><?= escape_html($entry['new_price']) ?></td>
                            <td><?= escape_html($entry['changed_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="archive-heading">
            <div class="panel-heading">
                <h2 id="archive-heading">Deleted-product archive</h2>
                <p>Written by the DELETE trigger before the product is removed</p>
            </div>
            <?php if ($deleted_products === []): ?>
                <p class="empty">No deleted products have been archived yet.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Original ID</th><th>Product</th><th>SKU</th><th>Category</th><th>Price</th><th>Deleted</th></tr></thead>
                    <tbody>
                    <?php foreach ($deleted_products as $entry): ?>
                        <tr>
                            <td><?= escape_html($entry['original_product_id']) ?></td>
                            <td><?= escape_html($entry['product_name']) ?></td>
                            <td><?= escape_html($entry['sku']) ?></td>
                            <td><?= escape_html($entry['category_name']) ?></td>
                            <td><?= escape_html($entry['price']) ?></td>
                            <td><?= escape_html($entry['deleted_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="orders-heading">
            <div class="panel-heading">
                <h2 id="orders-heading">Customer purchases</h2>
                <p>Stripe test-mode payments and stock reservations</p>
            </div>
            <?php if ($customer_orders === []): ?>
                <p class="empty">No customer purchases yet.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Order</th><th>Customer</th><th>Product</th><th>Qty</th><th>Unit price</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($customer_orders as $order): ?>
                        <tr>
                            <td>#<?= escape_html($order['order_id']) ?></td>
                            <td><?= escape_html($order['customer_name']) ?><br><?= escape_html($order['customer_email']) ?></td>
                            <td><?= escape_html($order['product_name']) ?><br><?= escape_html($order['sku']) ?></td>
                            <td><?= escape_html($order['quantity']) ?></td>
                            <td><?= escape_html($order['unit_price']) ?></td>
                            <td><?= escape_html(ucfirst($order['order_status'])) ?></td>
                            <td><?= escape_html($order['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="loans-heading">
            <div class="panel-heading">
                <h2 id="loans-heading">Customer borrowing</h2>
                <p>Available stock is restored when items are marked returned.</p>
            </div>
            <?php if ($customer_loans === []): ?>
                <p class="empty">No borrowed products yet.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Loan</th><th>Customer</th><th>Product</th><th>Qty</th><th>Return by</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($customer_loans as $loan): ?>
                        <tr>
                            <td>#<?= escape_html($loan['loan_id']) ?></td>
                            <td><?= escape_html($loan['customer_name']) ?><br><?= escape_html($loan['customer_email']) ?></td>
                            <td><?= escape_html($loan['product_name']) ?><br><?= escape_html($loan['sku']) ?></td>
                            <td><?= escape_html($loan['quantity']) ?></td>
                            <td><?= escape_html($loan['due_date']) ?></td>
                            <td><?= escape_html(ucfirst($loan['loan_status'])) ?></td>
                            <td>
                                <?php if ($loan['loan_status'] === 'active'): ?>
                                    <form method="post" action="admin.php">
                                        <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="return_loan">
                                        <input type="hidden" name="loan_id" value="<?= escape_html($loan['loan_id']) ?>">
                                        <button class="secondary" type="submit">Mark returned</button>
                                    </form>
                                <?php else: ?>
                                    <?= escape_html($loan['returned_at']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
