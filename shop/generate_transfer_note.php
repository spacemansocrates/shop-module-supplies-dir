<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['warehouse_id'])) {
    header('Location: /login.php');
    exit();
}

// --- DB Connection ---
require_once __DIR__ . '/config.php';
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}
// --- End of DB Connection ---

$transfer_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$warehouse_id = $_SESSION['warehouse_id'];

if (!$transfer_id) {
    die("Invalid Transfer ID.");
}

// Fetch main transfer details, joining all necessary tables for names
$sql_main = "
    SELECT 
        st.transfer_reference, st.shipped_at, st.notes,
        w.name as from_warehouse_name, w.address_line1 as warehouse_address, w.city as warehouse_city,
        s.name as to_shop_name,
        u_ship.full_name as shipped_by_name
    FROM stock_transfers st
    JOIN warehouses w ON st.from_warehouse_id = w.id
    JOIN shops s ON st.to_shop_id = s.id
    LEFT JOIN users u_ship ON st.shipped_by_user_id = u_ship.id
    WHERE st.id = ? AND st.from_warehouse_id = ? 
    LIMIT 1
";
$stmt_main = $pdo->prepare($sql_main);
$stmt_main->execute([$transfer_id, $warehouse_id]);
$transfer = $stmt_main->fetch();

if (!$transfer) {
    die("Transfer Note not found or you do not have permission to view it.");
}

// Fetch the items that were actually shipped
$sql_items = "
    SELECT 
        sti.quantity_shipped,
        p.sku, p.name as product_name, p.default_unit_of_measurement as uom
    FROM stock_transfer_items sti
    JOIN products p ON sti.product_id = p.id
    WHERE sti.stock_transfer_id = ? AND sti.quantity_shipped > 0
    ORDER BY p.name ASC
";
$stmt_items = $pdo->prepare($sql_items);
$stmt_items->execute([$transfer_id]);
$items = $stmt_items->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transfer Note: <?= htmlspecialchars($transfer['transfer_reference'] ?? '') ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background-color: #eee; }
        .note-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 40px;
            background-color: #fff;
            border: 1px solid #ccc;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .note-header h1 {
            font-size: 2.5rem;
            font-weight: bold;
            color: #333;
            margin: 0;
        }
        .note-header h2 {
            font-size: 1.5rem;
            color: #555;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .details-table td { padding: 5px 10px 5px 0; }
        .items-table th { background-color: #f2f2f2; }
        .footer-signatures {
            margin-top: 80px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 30%;
            text-align: center;
            padding-top: 10px;
            border-top: 1px solid #999;
        }
        .print-button { margin: 20px 0; }

        @media print {
            body { background-color: #fff; }
            .note-container {
                margin: 0;
                padding: 0;
                border: none;
                box-shadow: none;
                max-width: 100%;
            }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="container text-center no-print">
    <button onclick="window.print();" class="btn btn-primary print-button">
        <i class="fas fa-print"></i> Print this Note
    </button>
</div>

<div class="note-container">
    <header class="note-header mb-4">
        <div class="row">
            <div class="col-8">
                <h1>Supplies Direct</h1>
                <p class="text-muted mb-0"><?= htmlspecialchars($transfer['from_warehouse_name'] ?? 'Main Warehouse') ?></p>
                <p class="text-muted"><?= htmlspecialchars($transfer['warehouse_address'] ?? '') ?>, <?= htmlspecialchars($transfer['warehouse_city'] ?? '') ?></p>
            </div>
            <div class="col-4 text-right">
                <h2>Transfer Note</h2>
                <table class="details-table ml-auto">
                    <tr><td><strong>Ref #:</strong></td><td><?= htmlspecialchars($transfer['transfer_reference'] ?? 'N/A') ?></td></tr>
                    <tr><td><strong>Date:</strong></td><td><?= date('M j, Y', strtotime($transfer['shipped_at'] ?? 'now')) ?></td></tr>
                </table>
            </div>
        </div>
    </header>
    
    <div class="transfer-info mb-4">
        <div class="row">
            <div class="col-6">
                <strong>FROM (Warehouse):</strong><br>
                <?= htmlspecialchars($transfer['from_warehouse_name'] ?? '') ?>
            </div>
            <div class="col-6">
                <strong>TO (Shop):</strong><br>
                <?= htmlspecialchars($transfer['to_shop_name'] ?? '') ?>
            </div>
        </div>
    </div>

    <main>
        <table class="table table-bordered items-table">
            <thead>
                <tr>
                    <th style="width:10%;">SKU</th>
                    <th>Product Description</th>
                    <th style="width:15%;" class="text-right">Quantity</th>
                    <th style="width:15%;" class="text-center">Unit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['sku'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?></td>
                    <td class="text-right"><?= htmlspecialchars((string)($item['quantity_shipped'] ?? 0)) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['uom'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </main>
    
    <?php if(!empty($transfer['notes'])): ?>
    <div class="notes mt-4">
        <strong>Notes:</strong>
        <p class="text-muted"><?= nl2br(htmlspecialchars($transfer['notes'] ?? '')) ?></p>
    </div>
    <?php endif; ?>

    <footer class="footer-signatures">
        <div class="signature-box">
            Dispatched By:<br>
            <strong><?= htmlspecialchars($transfer['shipped_by_name'] ?? 'N/A') ?></strong>
        </div>
        <div class="signature-box">
            Collected By (Print Name & Sign)
        </div>
        <div class="signature-box">
            Date Received
        </div>
    </footer>
</div>

</body>
</html>