<?php
require __DIR__ . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'add_product') {
    $stmt = $pdo->prepare('INSERT INTO products (name, sku, category, unit, price, stock) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        trim($_POST['name'] ?? ''),
        trim($_POST['sku'] ?? ''),
        trim($_POST['category'] ?? ''),
        trim($_POST['unit'] ?? ''),
        (float)($_POST['price'] ?? 0),
        (int)($_POST['stock'] ?? 0),
    ]);
    flash('货品已新增');
    header('Location: index.php');
    exit;
}

if ($action === 'update_product') {
    $stmt = $pdo->prepare('UPDATE products SET name = ?, sku = ?, category = ?, unit = ?, price = ? WHERE id = ?');
    $stmt->execute([
        trim($_POST['name'] ?? ''),
        trim($_POST['sku'] ?? ''),
        trim($_POST['category'] ?? ''),
        trim($_POST['unit'] ?? ''),
        (float)($_POST['price'] ?? 0),
        (int)($_POST['id'] ?? 0),
    ]);
    flash('货品信息已更新');
    header('Location: index.php');
    exit;
}

if ($action === 'delete_product') {
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([(int)($_POST['id'] ?? 0)]);
    flash('货品已删除', 'danger');
    header('Location: index.php');
    exit;
}

if ($action === 'stock_movement') {
    $type = $_POST['type'] ?? 'in';
    $quantity = max(1, (int)($_POST['quantity'] ?? 0));
    $productId = (int)($_POST['product_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO stock_movements (product_id, type, quantity, note) VALUES (?, ?, ?, ?)');
    $stmt->execute([$productId, $type, $quantity, $note]);

    $delta = $type === 'out' ? -$quantity : $quantity;
    $stmt = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
    $stmt->execute([$delta, $productId]);
    $pdo->commit();

    flash('库存流水已记录');
    header('Location: index.php');
    exit;
}

$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
$movements = $pdo->query('SELECT m.*, p.name FROM stock_movements m JOIN products p ON p.id = m.product_id ORDER BY m.id DESC LIMIT 10')->fetchAll();

$period = $_GET['period'] ?? 'week';
$validPeriods = ['day', 'week', 'month'];
if (!in_array($period, $validPeriods, true)) {
    $period = 'week';
}

$start = new DateTime('today');
$end = clone $start;
$periodLabel = '本周';
if ($period === 'day') {
    $periodLabel = '今日';
    $end->modify('+1 day');
} elseif ($period === 'month') {
    $periodLabel = '本月';
    $start->modify('first day of this month');
    $end = (clone $start)->modify('+1 month');
} else {
    $periodLabel = '本周';
    $start->modify('monday this week');
    $end = (clone $start)->modify('+1 week');
}

$statsStmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN m.type = 'in' THEN m.quantity ELSE 0 END) AS inbound_qty,
        SUM(CASE WHEN m.type = 'out' THEN m.quantity ELSE 0 END) AS outbound_qty,
        SUM(CASE WHEN m.type = 'in' THEN m.quantity * p.price ELSE 0 END) AS inbound_value,
        SUM(CASE WHEN m.type = 'out' THEN m.quantity * p.price ELSE 0 END) AS outbound_value
     FROM stock_movements m
     JOIN products p ON p.id = m.product_id
     WHERE m.created_at >= :start AND m.created_at < :end"
);
$statsStmt->execute([
    'start' => $start->format('Y-m-d H:i:s'),
    'end' => $end->format('Y-m-d H:i:s'),
]);
$stats = $statsStmt->fetch() ?: [
    'inbound_qty' => 0,
    'outbound_qty' => 0,
    'inbound_value' => 0,
    'outbound_value' => 0,
];

$detailStmt = $pdo->prepare(
    "SELECT
        p.name,
        p.sku,
        p.category,
        p.unit,
        p.price,
        SUM(CASE WHEN m.type = 'in' THEN m.quantity ELSE 0 END) AS inbound_qty,
        SUM(CASE WHEN m.type = 'out' THEN m.quantity ELSE 0 END) AS outbound_qty
     FROM stock_movements m
     JOIN products p ON p.id = m.product_id
     WHERE m.created_at >= :start AND m.created_at < :end
     GROUP BY p.id
     ORDER BY (SUM(m.quantity)) DESC
     LIMIT 10"
);
$detailStmt->execute([
    'start' => $start->format('Y-m-d H:i:s'),
    'end' => $end->format('Y-m-d H:i:s'),
]);
$detailStats = $detailStmt->fetchAll();

$totalProducts = $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$totalStock = $pdo->query('SELECT COALESCE(SUM(stock),0) FROM products')->fetchColumn();
$latestInbound = $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'in' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$latestOutbound = $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'out' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>公牛旗舰店 - 进销存后台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f4f7fb;
            font-family: 'Segoe UI', 'PingFang SC', sans-serif;
        }
        .hero {
            background: linear-gradient(120deg, #0d6efd, #3b82f6);
            color: #fff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 20px 35px rgba(13, 110, 253, 0.25);
        }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            gap: 16px;
            align-items: center;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            font-size: 24px;
        }
        .table thead {
            background: #f1f5f9;
        }
        .badge-pill {
            padding: 6px 12px;
            border-radius: 999px;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="hero mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-2">公牛旗舰店进销存后台</h1>
                <p class="mb-0">统一管理货品信息、库存流转与出入库记录，支持快速统计。</p>
            </div>
            <span class="badge bg-light text-primary px-3 py-2">管家婆风格 - 轻量版</span>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo h($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-box-seam"></i></div>
                <div>
                    <small class="text-muted">货品数量</small>
                    <h4 class="mb-0"><?php echo h((string)$totalProducts); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-archive"></i></div>
                <div>
                    <small class="text-muted">库存总量</small>
                    <h4 class="mb-0"><?php echo h((string)$totalStock); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-arrow-down-square"></i></div>
                <div>
                    <small class="text-muted">近7天入库</small>
                    <h4 class="mb-0"><?php echo h((string)$latestInbound); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-arrow-up-square"></i></div>
                <div>
                    <small class="text-muted">近7天出库</small>
                    <h4 class="mb-0"><?php echo h((string)$latestOutbound); ?></h4>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card p-3">
                <h5 class="mb-3">新增货品</h5>
                <form method="post" class="vstack gap-3">
                    <input type="hidden" name="action" value="add_product">
                    <div>
                        <label class="form-label">货品名称</label>
                        <input class="form-control" name="name" required>
                    </div>
                    <div>
                        <label class="form-label">SKU/编码</label>
                        <input class="form-control" name="sku" required>
                    </div>
                    <div>
                        <label class="form-label">品类</label>
                        <input class="form-control" name="category">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">单位</label>
                            <input class="form-control" name="unit" placeholder="件/箱">
                        </div>
                        <div class="col-6">
                            <label class="form-label">单价</label>
                            <input class="form-control" name="price" type="number" step="0.01">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">初始库存</label>
                        <input class="form-control" name="stock" type="number" value="0">
                    </div>
                    <button class="btn btn-primary w-100">保存货品</button>
                </form>
            </div>

            <div class="card p-3 mt-4">
                <h5 class="mb-3">登记出入库</h5>
                <form method="post" class="vstack gap-3">
                    <input type="hidden" name="action" value="stock_movement">
                    <div>
                        <label class="form-label">货品选择</label>
                        <select class="form-select" name="product_id" required>
                            <option value="">请选择货品</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo h((string)$product['id']); ?>"><?php echo h($product['name']); ?> (库存 <?php echo h((string)$product['stock']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">类型</label>
                            <select class="form-select" name="type">
                                <option value="in">入库</option>
                                <option value="out">出库</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">数量</label>
                            <input class="form-control" name="quantity" type="number" min="1" value="1" required>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">备注</label>
                        <textarea class="form-control" name="note" rows="2"></textarea>
                    </div>
                    <button class="btn btn-success w-100">提交记录</button>
                </form>
            </div>

            <div class="card p-3 mt-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><?php echo h($periodLabel); ?>统计</h5>
                    <div class="btn-group" role="group">
                        <a class="btn btn-outline-primary btn-sm<?php echo $period === 'day' ? ' active' : ''; ?>" href="?period=day">按日</a>
                        <a class="btn btn-outline-primary btn-sm<?php echo $period === 'week' ? ' active' : ''; ?>" href="?period=week">按周</a>
                        <a class="btn btn-outline-primary btn-sm<?php echo $period === 'month' ? ' active' : ''; ?>" href="?period=month">按月</a>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="stat-card">
                            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-box-arrow-in-down"></i></div>
                            <div>
                                <small class="text-muted">入库数量</small>
                                <h5 class="mb-0"><?php echo h((string)$stats['inbound_qty']); ?></h5>
                                <small class="text-muted">金额 ￥<?php echo h(number_format((float)$stats['inbound_value'], 2)); ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card">
                            <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-box-arrow-up"></i></div>
                            <div>
                                <small class="text-muted">出库数量</small>
                                <h5 class="mb-0"><?php echo h((string)$stats['outbound_qty']); ?></h5>
                                <small class="text-muted">金额 ￥<?php echo h(number_format((float)$stats['outbound_value'], 2)); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <?php $displayEnd = (clone $end)->modify('-1 day'); ?>
                    <small class="text-muted">统计周期：<?php echo h($start->format('Y-m-d')); ?> 至 <?php echo h($displayEnd->format('Y-m-d')); ?></small>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card p-3 mb-4">
                <h5 class="mb-3">货品列表</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>名称</th>
                            <th>SKU</th>
                            <th>分类</th>
                            <th>单价</th>
                            <th>库存</th>
                            <th class="text-end">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo h($product['name']); ?></td>
                                <td><span class="badge text-bg-light badge-pill"><?php echo h($product['sku']); ?></span></td>
                                <td><?php echo h($product['category']); ?></td>
                                <td>￥<?php echo h(number_format((float)$product['price'], 2)); ?></td>
                                <td><?php echo h((string)$product['stock']); ?></td>
                                <td class="text-end">
                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?php echo h((string)$product['id']); ?>">编辑</button>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="id" value="<?php echo h((string)$product['id']); ?>">
                                        <button class="btn btn-outline-danger btn-sm" onclick="return confirm('确定删除该货品吗？')">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card p-3 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">最近出入库</h5>
                    <span class="text-muted">仅显示最新10条</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>货品</th>
                            <th>类型</th>
                            <th>数量</th>
                            <th>备注</th>
                            <th>时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($movements as $movement): ?>
                            <tr>
                                <td><?php echo h($movement['name']); ?></td>
                                <td>
                                    <?php if ($movement['type'] === 'in'): ?>
                                        <span class="badge text-bg-success badge-pill">入库</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger badge-pill">出库</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h((string)$movement['quantity']); ?></td>
                                <td><?php echo h($movement['note']); ?></td>
                                <td><?php echo h($movement['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">按货品统计（Top 10）</h5>
                    <span class="text-muted"><?php echo h($periodLabel); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>货品</th>
                            <th>SKU</th>
                            <th>分类</th>
                            <th>单价</th>
                            <th>入库</th>
                            <th>出库</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$detailStats): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">暂无统计数据</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detailStats as $detail): ?>
                                <tr>
                                    <td><?php echo h($detail['name']); ?></td>
                                    <td><span class="badge text-bg-light badge-pill"><?php echo h($detail['sku']); ?></span></td>
                                    <td><?php echo h($detail['category']); ?></td>
                                    <td>￥<?php echo h(number_format((float)$detail['price'], 2)); ?></td>
                                    <td><?php echo h((string)$detail['inbound_qty']); ?></td>
                                    <td><?php echo h((string)$detail['outbound_qty']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($products as $product): ?>
    <div class="modal fade" id="editModal<?php echo h((string)$product['id']); ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="update_product">
                    <input type="hidden" name="id" value="<?php echo h((string)$product['id']); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">编辑货品</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body vstack gap-3">
                        <div>
                            <label class="form-label">货品名称</label>
                            <input class="form-control" name="name" value="<?php echo h($product['name']); ?>" required>
                        </div>
                        <div>
                            <label class="form-label">SKU/编码</label>
                            <input class="form-control" name="sku" value="<?php echo h($product['sku']); ?>" required>
                        </div>
                        <div>
                            <label class="form-label">品类</label>
                            <input class="form-control" name="category" value="<?php echo h($product['category']); ?>">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">单位</label>
                                <input class="form-control" name="unit" value="<?php echo h($product['unit']); ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">单价</label>
                                <input class="form-control" name="price" type="number" step="0.01" value="<?php echo h((string)$product['price']); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">取消</button>
                        <button class="btn btn-primary" type="submit">保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
