<?php

function tradingEnsureColumn(mysqli $db, string $table, string $column, string $definition): void {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' AND COLUMN_NAME='$column'
        LIMIT 1")->num_rows > 0;
    if (!$exists) {
        $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function tradingEnsureTables(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS aggregate_suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_code VARCHAR(30) NOT NULL UNIQUE,
        supplier_name VARCHAR(120) NOT NULL,
        contact_person VARCHAR(100) DEFAULT '',
        phone VARCHAR(20) DEFAULT '',
        mobile VARCHAR(20) DEFAULT '',
        gstin VARCHAR(20) DEFAULT '',
        address TEXT DEFAULT NULL,
        city VARCHAR(60) DEFAULT '',
        state VARCHAR(60) DEFAULT '',
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS aggregate_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_code VARCHAR(30) NOT NULL UNIQUE,
        item_name VARCHAR(150) NOT NULL,
        description TEXT DEFAULT NULL,
        uom VARCHAR(20) NOT NULL DEFAULT 'MT',
        hsn_code VARCHAR(20) DEFAULT '',
        rate_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0,
        conversion_ratio DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS aggregate_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_code VARCHAR(30) NOT NULL UNIQUE,
        source_name VARCHAR(120) NOT NULL,
        description VARCHAR(255) DEFAULT '',
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS trading_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(40) NOT NULL UNIQUE,
        receipt_date DATE NOT NULL,
        vendor_id INT NOT NULL,
        item_id INT NOT NULL,
        source_id INT DEFAULT NULL,
        vehicle_no VARCHAR(30) DEFAULT '',
        supplier_invoice_no VARCHAR(60) DEFAULT '',
        quantity_received DECIMAL(12,3) NOT NULL DEFAULT 0,
        deduction_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
        deduction_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
        final_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
        royalty_cmtr DECIMAL(12,3) NOT NULL DEFAULT 0,
        conversion_ratio DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
        royalty_mt DECIMAL(12,3) NOT NULL DEFAULT 0,
        rate_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remarks TEXT DEFAULT NULL,
        status ENUM('Draft','Confirmed','Cancelled') NOT NULL DEFAULT 'Draft',
        created_by INT NOT NULL DEFAULT 0,
        company_id INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_trading_receipt_date (receipt_date),
        INDEX idx_trading_vendor (vendor_id),
        INDEX idx_trading_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS doc_sequences (
        seq_key VARCHAR(50) PRIMARY KEY,
        last_val INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function tradingCurrentFY(): string {
    $m = (int)date('m');
    $y = (int)date('Y');
    $fy_start = $m >= 4 ? $y : $y - 1;
    $fy_end = $fy_start + 1;
    return str_pad($fy_start % 100, 2, '0', STR_PAD_LEFT) . str_pad($fy_end % 100, 2, '0', STR_PAD_LEFT);
}

function tradingNextReceiptNo(mysqli $db, bool $consume = false): string {
    $fy = tradingCurrentFY();
    $seq_key = "trading_receipt_fy{$fy}";
    $db->query("INSERT IGNORE INTO doc_sequences (seq_key, last_val) VALUES ('$seq_key', 0)");

    $row = $db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$seq_key'")->fetch_assoc();
    $last_val = (int)($row['last_val'] ?? 0);

    $prefix = "TR/{$fy}/";
    $max_row = $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(receipt_no,'/',-1) AS UNSIGNED)) AS mx
        FROM trading_receipts
        WHERE receipt_no LIKE '" . $db->real_escape_string($prefix) . "%'")->fetch_assoc();
    $db_max = (int)($max_row['mx'] ?? 0);
    if ($db_max > $last_val) {
        $last_val = $db_max;
        $db->query("UPDATE doc_sequences SET last_val=$last_val WHERE seq_key='$seq_key'");
    }

    $next = $last_val + 1;
    if ($consume) {
        $db->query("UPDATE doc_sequences SET last_val=$next WHERE seq_key='$seq_key'");
    }

    return "TR/{$fy}/" . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function tradingComputeValues(float $quantity_received, float $deduction_pct, float $royalty_cmtr, float $conversion_ratio, float $rate_per_unit): array {
    if ($conversion_ratio <= 0) $conversion_ratio = 1;
    if ($deduction_pct < 0) $deduction_pct = 0;
    if ($deduction_pct > 100) $deduction_pct = 100;

    $deduction_qty = round($quantity_received * $deduction_pct / 100, 3);
    $final_qty = round($quantity_received - $deduction_qty, 3);
    if ($final_qty < 0) $final_qty = 0;
    $royalty_mt = round($royalty_cmtr / $conversion_ratio, 3);
    $amount = round($final_qty * $rate_per_unit, 2);

    return [
        'deduction_qty' => $deduction_qty,
        'final_qty' => $final_qty,
        'royalty_mt' => $royalty_mt,
        'amount' => $amount,
        'conversion_ratio' => $conversion_ratio,
    ];
}

function tradingFetchAll(mysqli $db, string $sql): array {
    $res = $db->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function tradingFetchRow(mysqli $db, string $sql): array {
    $res = $db->query($sql);
    return $res ? ($res->fetch_assoc() ?: []) : [];
}
