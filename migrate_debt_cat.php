<?php
require __DIR__ . '/db.php';
$r = $pdo->exec("UPDATE debts SET debt_category = 'مصاريف أخرى' WHERE debt_category = 'المصاريف الخارجية'");
echo 'Updated rows: ' . $r;
