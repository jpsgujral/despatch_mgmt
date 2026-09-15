<?php

if (!function_exists('fleetSafeAddCol')) {
    function fleetSafeAddCol($db, string $table, string $col, string $def): void {
        $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
        if (!$exists) {
            $db->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    }
}

function fleetEnsureLeaseAgents($db): void {
    $db->query("CREATE TABLE IF NOT EXISTS fleet_lease_agents (
        id                    INT AUTO_INCREMENT PRIMARY KEY,
        agent_code            VARCHAR(20) NOT NULL UNIQUE,
        agent_name            VARCHAR(120) NOT NULL,
        agent_short_name       VARCHAR(60) DEFAULT '',
        contact_person        VARCHAR(120) DEFAULT '',
        phone                 VARCHAR(20) DEFAULT '',
        mobile                VARCHAR(20) DEFAULT '',
        email                 VARCHAR(120) DEFAULT '',
        default_margin_per_mt DECIMAL(10,2) DEFAULT 0,
        status                ENUM('Active','Inactive') DEFAULT 'Active',
        company_id            INT DEFAULT 1,
        notes                 TEXT,
        created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    fleetSafeAddCol($db, 'fleet_lease_agents', 'company_id',          "INT DEFAULT 1");
    fleetSafeAddCol($db, 'fleet_lease_agents', 'agent_short_name',    "VARCHAR(60) DEFAULT ''");
    fleetSafeAddCol($db, 'fleet_lease_agents', 'gst_rate',            "DECIMAL(5,2) DEFAULT 0");
    fleetSafeAddCol($db, 'fleet_lease_agents', 'rcm_applicable',      "ENUM('No','Yes') DEFAULT 'No'");
    fleetSafeAddCol($db, 'fleet_lease_agents', 'tds_rate',            "DECIMAL(5,2) DEFAULT 0");
    // Unified tax type/rate/date fields
    fleetSafeAddCol($db, 'fleet_lease_agents', 'tax_type',            "ENUM('None','GST','RCM','TDS') DEFAULT 'None'");
    fleetSafeAddCol($db, 'fleet_lease_agents', 'tax_rate',            "DECIMAL(5,2) DEFAULT 0");
    fleetSafeAddCol($db, 'fleet_lease_agents', 'tax_applicable_from', "DATE DEFAULT NULL");
    fleetSafeAddCol($db, 'fleet_lease_agents', 'billing_model',       "ENUM('margin_deduction','mgmt_fee') DEFAULT 'margin_deduction'");
}

function fleetEnsureLeaseTripFields($db): void {
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_id', 'INT DEFAULT NULL AFTER freight_amount');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_name', "VARCHAR(120) DEFAULT '' AFTER lease_agent_id");
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_billing_model', "VARCHAR(30) DEFAULT 'margin_deduction' AFTER lease_agent_name");
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_margin_per_mt', 'DECIMAL(10,2) DEFAULT 0 AFTER lease_agent_billing_model');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_amount', 'DECIMAL(12,2) DEFAULT 0 AFTER lease_agent_margin_per_mt');
    fleetSafeAddCol($db, 'fleet_trips', 'net_freight_amount', 'DECIMAL(12,2) DEFAULT 0 AFTER lease_agent_amount');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_misc_deduction', 'DECIMAL(12,2) DEFAULT 0 AFTER net_freight_amount');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_misc_deduction_remarks', "VARCHAR(255) DEFAULT '' AFTER lease_agent_misc_deduction");
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_billing_status', "ENUM('Pending','Billed') DEFAULT 'Pending' AFTER lease_agent_misc_deduction_remarks");
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_invoice_no', "VARCHAR(80) DEFAULT '' AFTER lease_agent_billing_status");
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_invoice_date', 'DATE DEFAULT NULL AFTER lease_agent_invoice_no');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_billing_remarks', "VARCHAR(255) DEFAULT '' AFTER lease_agent_invoice_date");
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_bill_to_company_id', 'INT DEFAULT NULL AFTER lease_agent_billing_remarks');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_fuel', 'DECIMAL(12,2) DEFAULT 0 AFTER lease_agent_bill_to_company_id');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_driver_advance', 'DECIMAL(12,2) DEFAULT 0 AFTER lease_agent_fuel');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_driver_fooding', 'DECIMAL(12,2) DEFAULT 0 AFTER lease_agent_driver_advance');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_toll', 'DECIMAL(12,2) DEFAULT 0 AFTER lease_agent_driver_fooding');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_misc_expense', 'DECIMAL(12,2) DEFAULT 0 AFTER lease_agent_toll');
    fleetSafeAddCol($db, 'fleet_trips', 'lease_agent_profit', 'DECIMAL(12,2) DEFAULT 0 AFTER lease_agent_misc_expense');
}

function fleetEnsureLeaseAgentPayments($db): void {
    $db->query("CREATE TABLE IF NOT EXISTS fleet_lease_agent_payments (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        payment_no         VARCHAR(40) DEFAULT '',
        lease_agent_id     INT NOT NULL,
        payment_date       DATE NOT NULL,
        date_from          DATE NOT NULL,
        date_to            DATE NOT NULL,
        amount             DECIMAL(12,2) DEFAULT 0,
        total_payable      DECIMAL(12,2) DEFAULT 0,
        balance_amount     DECIMAL(12,2) DEFAULT 0,
        reference_no       VARCHAR(120) DEFAULT '',
        utr_no             VARCHAR(120) DEFAULT '',
        bank_name          VARCHAR(120) DEFAULT '',
        notes              TEXT,
        status             ENUM('Paid','Partial','Unpaid','Cancelled') DEFAULT 'Paid',
        created_by         INT DEFAULT 0,
        created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_by         INT DEFAULT NULL,
        updated_at         TIMESTAMP NULL DEFAULT NULL,
        INDEX (lease_agent_id),
        INDEX (payment_date),
        INDEX (date_from, date_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS fleet_lease_agent_payment_trips (
        payment_id         INT NOT NULL,
        trip_id            INT NOT NULL,
        created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (payment_id, trip_id),
        INDEX (trip_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'payment_no', "VARCHAR(40) DEFAULT '' AFTER id");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'company_id', "INT DEFAULT NULL AFTER lease_agent_id");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'agent_invoice_no', "VARCHAR(120) DEFAULT '' AFTER company_id");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'date_from', "DATE NOT NULL AFTER payment_date");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'date_to', "DATE NOT NULL AFTER date_from");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'total_payable', "DECIMAL(12,2) DEFAULT 0 AFTER amount");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'balance_amount', "DECIMAL(12,2) DEFAULT 0 AFTER total_payable");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'payment_mode', "VARCHAR(40) DEFAULT '' AFTER balance_amount");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'reference_no', "VARCHAR(120) DEFAULT '' AFTER payment_mode");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'utr_no', "VARCHAR(120) DEFAULT '' AFTER reference_no");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'bank_name', "VARCHAR(120) DEFAULT '' AFTER utr_no");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'notes', "TEXT AFTER bank_name");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'status', "ENUM('Paid','Partial','Unpaid','Cancelled') DEFAULT 'Paid' AFTER notes");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'created_by', "INT DEFAULT 0 AFTER status");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'updated_by', "INT DEFAULT NULL AFTER created_at");
    fleetSafeAddCol($db, 'fleet_lease_agent_payments', 'updated_at', "TIMESTAMP NULL DEFAULT NULL AFTER updated_by");

    $db->query("ALTER TABLE fleet_lease_agent_payments
        MODIFY status ENUM('Paid','Partial','Unpaid','Cancelled') DEFAULT 'Paid'");

    fleetSafeAddCol($db, 'fleet_lease_agent_payment_trips', 'payment_id', "INT NOT NULL");
    fleetSafeAddCol($db, 'fleet_lease_agent_payment_trips', 'trip_id', "INT NOT NULL");
    fleetSafeAddCol($db, 'fleet_lease_agent_payment_trips', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

function fleetLeaseAgentBasePayableSql(string $tripAlias = 't', string $agentAlias = 'la'): string {
    return "CASE WHEN COALESCE({$tripAlias}.lease_agent_billing_model, {$agentAlias}.billing_model, 'margin_deduction') = 'mgmt_fee'
                 THEN COALESCE({$tripAlias}.lease_agent_amount, 0)
                 ELSE COALESCE({$tripAlias}.net_freight_amount, ({$tripAlias}.freight_amount - COALESCE({$tripAlias}.lease_agent_amount, 0)))
            END";
}

function fleetLeaseAgentTripBasePayable(array $trip): float {
    $model = $trip['lease_agent_billing_model'] ?? ($trip['billing_model'] ?? 'margin_deduction');
    if ($model === 'mgmt_fee') {
        return (float)($trip['lease_agent_amount'] ?? 0);
    }
    return (float)($trip['net_freight_amount'] ?? (((float)($trip['freight_amount'] ?? 0)) - ((float)($trip['lease_agent_amount'] ?? 0))));
}

function fleetCalculateLeaseAgentTripBreakdown(array $t, float $freightAmount = 0, float $totalWeight = 0): array {
    $billingModel = $t['lease_agent_billing_model'] ?? ($t['billing_model'] ?? 'margin_deduction');
    $marginPerMt = (float)($t['lease_agent_margin_per_mt'] ?? 0);
    $weight = $totalWeight > 0 ? $totalWeight : (float)($t['total_weight'] ?? 0);
    $grossFreight = $freightAmount > 0 ? $freightAmount : (float)($t['freight_amount'] ?? 0);

    $vendorGrossRate = $weight > 0 ? round($grossFreight / $weight, 2) : 0.0;

    if ($billingModel === 'mgmt_fee') {
        $agentRatePerMt = $marginPerMt;
        $mgmtFeeTotal = round($marginPerMt * $weight, 2);
        $basePayable = (float)($t['lease_agent_amount'] ?? $mgmtFeeTotal);
        if ($basePayable <= 0 && $mgmtFeeTotal > 0) {
            $basePayable = $mgmtFeeTotal;
        }
    } else {
        $agentRatePerMt = max(0, $vendorGrossRate - $marginPerMt);
        $netFreightTotal = round(max(0, $grossFreight - ($marginPerMt * $weight)), 2);
        $basePayable = (float)($t['net_freight_amount'] ?? $netFreightTotal);
        if ($basePayable <= 0 && $netFreightTotal > 0) {
            $basePayable = $netFreightTotal;
        }
    }

    $miscDeduction = (float)($t['lease_agent_misc_deduction'] ?? 0);
    $taxType = trim((string)($t['lease_agent_tax_type'] ?? ($t['tax_type'] ?? 'None')));
    if ($taxType === '') $taxType = 'None';
    $taxRate = (float)($t['lease_agent_tax_rate'] ?? ($t['tax_rate'] ?? 0));
    $taxAmount = ($taxType !== 'None' && $taxRate > 0) ? round($basePayable * $taxRate / 100, 2) : 0.0;

    $taxAdjustment = 0.0;
    if ($taxType === 'GST') {
        $taxAdjustment = $taxAmount;
    } elseif ($taxType === 'TDS') {
        $taxAdjustment = -$taxAmount;
    }

    $finalPayable = max(0, round($basePayable + $taxAdjustment - $miscDeduction, 2));

    return [
        'billing_model'     => $billingModel,
        'vendor_gross_rate' => $vendorGrossRate,
        'margin_per_mt'     => $marginPerMt,
        'agent_rate_per_mt' => $agentRatePerMt,
        'base_payable'      => $basePayable,
        'misc_deduction'    => $miscDeduction,
        'tax_type'          => $taxType,
        'tax_rate'          => $taxRate,
        'tax_amount'        => $taxAmount,
        'final_payable'     => $finalPayable,
    ];
}

