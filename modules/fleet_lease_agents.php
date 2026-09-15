<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/fleet_lease_agent_helper.php';

$db = getDB();
requirePerm('fleet_lease_agents', 'view');
fleetEnsureLeaseAgents($db);

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

function lease_agent_plain($value) {
    return trim(html_entity_decode((string)($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function lease_agent_sql($value) {
    return getDB()->real_escape_string(lease_agent_plain($value));
}

if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];
    $refs = $db->query("SELECT COUNT(*) c FROM fleet_trips WHERE lease_agent_id=$did")->fetch_assoc();
    if ((int)($refs['c'] ?? 0) > 0) {
        showAlert('danger', 'Cannot delete. Trips are linked to this lease agent. Set status to Inactive instead.');
    } else {
        $db->query("DELETE FROM fleet_lease_agents WHERE id=$did");
        showAlert('success', 'Lease agent deleted.');
    }
    redirect('fleet_lease_agents.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_agent'])) {
    requirePerm('fleet_lease_agents', $id > 0 ? 'update' : 'create');

    $code = lease_agent_sql($_POST['agent_code'] ?? '');
    $name = lease_agent_sql($_POST['agent_name'] ?? '');
    $short_name = lease_agent_sql($_POST['agent_short_name'] ?? '');
    $contact = lease_agent_sql($_POST['contact_person'] ?? '');
    $phone = lease_agent_sql($_POST['phone'] ?? '');
    $mobile = lease_agent_sql($_POST['mobile'] ?? '');
    $email = lease_agent_sql($_POST['email'] ?? '');
    $billing_model = in_array(($_POST['billing_model'] ?? 'margin_deduction'), ['margin_deduction', 'mgmt_fee'], true) ? $_POST['billing_model'] : 'margin_deduction';
    $margin = (float)($_POST['default_margin_per_mt'] ?? 0);
    $status = in_array(($_POST['status'] ?? 'Active'), ['Active', 'Inactive'], true) ? $_POST['status'] : 'Active';
    $company_id = activeCompanyId();
    $notes = lease_agent_sql($_POST['notes'] ?? '');
    $tax_type = in_array(($_POST['tax_type'] ?? 'None'), ['None','GST','RCM','TDS'], true) ? $_POST['tax_type'] : 'None';
    $tax_rate = ($tax_type === 'None') ? 0 : min(100, max(0, (float)($_POST['tax_rate'] ?? 0)));
    $tax_from_raw = trim($_POST['tax_applicable_from'] ?? '');
    $tax_applicable_from_sql = ($tax_type !== 'None' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tax_from_raw))
        ? "'" . $db->real_escape_string($tax_from_raw) . "'"
        : 'NULL';

    if ($code === '' || $name === '') {
        showAlert('danger', 'Agent Code and Agent Name are required.');
        redirect("fleet_lease_agents.php?action=" . ($id > 0 ? "edit&id=$id" : 'add'));
    }

    if ($id > 0) {
        $db->query("UPDATE fleet_lease_agents SET
            agent_code='$code', agent_name='$name', agent_short_name='$short_name', contact_person='$contact',
            phone='$phone', mobile='$mobile', email='$email', billing_model='$billing_model',
            default_margin_per_mt=$margin, status='$status', company_id=$company_id, notes='$notes',
            tax_type='$tax_type', tax_rate=$tax_rate, tax_applicable_from=$tax_applicable_from_sql
            WHERE id=$id");
        if ($db->error) {
            showAlert('danger', 'DB error: ' . htmlspecialchars($db->error));
            redirect("fleet_lease_agents.php?action=edit&id=$id");
        }
        showAlert('success', 'Lease agent updated.');
    } else {
        $db->query("INSERT INTO fleet_lease_agents
            (agent_code, agent_name, agent_short_name, contact_person, phone, mobile, email,
             billing_model, default_margin_per_mt, status, company_id, notes, tax_type, tax_rate, tax_applicable_from)
            VALUES ('$code', '$name', '$short_name', '$contact', '$phone', '$mobile', '$email',
            '$billing_model', $margin, '$status', $company_id, '$notes', '$tax_type', $tax_rate, $tax_applicable_from_sql)");
        if ($db->error) {
            showAlert('danger', 'DB error: ' . htmlspecialchars($db->error));
            redirect("fleet_lease_agents.php?action=add");
        }
        showAlert('success', 'Lease agent added.');
    }
    redirect('fleet_lease_agents.php');
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-person-badge me-2"></i>Lease Agents';</script>

<?php if ($action === 'list'): ?>
<?php
$rows = $db->query("SELECT la.*, co.company_name
    FROM fleet_lease_agents la
    LEFT JOIN companies co ON la.company_id=co.id
    ORDER BY co.company_name ASC, la.status ASC, la.agent_name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="fleet_lease_agent_dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="fleet_lease_agents.php" class="btn btn-sm btn-primary active"><i class="bi bi-person-badge me-1"></i>Agent Master</a>
        <a href="fleet_lease_agent_payments.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-cash-stack me-1"></i>Payments</a>
        <a href="fleet_lease_agent_report.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-bar-graph me-1"></i>Report</a>
    </div>
    <?php if (canDo('fleet_lease_agents', 'create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Agent</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Short Name</th>
                        <th>Agent Name</th>
                        <th>Model</th>
                        <th>Contact</th>
                        <th class="text-end">Rate / Margin (MT)</th>
                        <th>Tax</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $tt = $row['tax_type'] ?? 'None';
                        $tr = (float)($row['tax_rate'] ?? 0);
                        $bm = $row['billing_model'] ?? 'margin_deduction';
                        $tax_badge_map = [
                            'GST' => ['label' => 'GST',  'class' => 'bg-info text-dark'],
                            'RCM' => ['label' => 'RCM',  'class' => 'bg-warning text-dark'],
                            'TDS' => ['label' => 'TDS',  'class' => 'bg-secondary'],
                        ];
                    ?>
                    <tr>
                        <td><span class="badge bg-dark"><?= htmlspecialchars($row['agent_code']) ?></span></td>
                        <td class="fw-semibold"><?= htmlspecialchars($row['agent_short_name'] ?: '-') ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($row['agent_name']) ?></td>
                        <td>
                            <?php if ($bm === 'mgmt_fee'): ?>
                                <span class="badge bg-primary text-white"><i class="bi bi-gear-fill me-1"></i>Fixed Mgmt Fee</span>
                            <?php else: ?>
                                <span class="badge bg-secondary text-white"><i class="bi bi-percent me-1"></i>Margin Pass-Through</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['contact_person'] ?: ($row['mobile'] ?: '—')) ?></td>
                        <td class="text-end">
                            <span class="fw-bold <?= $bm === 'mgmt_fee' ? 'text-primary' : '' ?>">₹<?= number_format((float)$row['default_margin_per_mt'], 2) ?></span>
                            <small class="text-muted d-block" style="font-size:.75rem"><?= $bm === 'mgmt_fee' ? 'Mgmt Fee / MT' : 'Margin / MT' ?></small>
                        </td>
                        <td>
                            <?php if ($tt !== 'None' && isset($tax_badge_map[$tt])): ?>
                                <span class="badge <?= $tax_badge_map[$tt]['class'] ?>"><?= $tax_badge_map[$tt]['label'] ?></span>
                                <?php if ($tr > 0): ?>
                                <span class="ms-1 fw-semibold" style="font-size:.82rem"><?= number_format($tr, 2) ?>%</span>
                                <?php endif; ?>
                                <?php
                                    $taf = $row['tax_applicable_from'] ?? '';
                                    if ($taf && $taf !== '0000-00-00'):
                                ?>
                                <br><small class="text-muted">w.e.f. <?= date('d/m/Y', strtotime($taf)) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?= $row['status'] === 'Active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td>
                            <?php if (canDo('fleet_lease_agents', 'update')): ?>
                            <a href="?action=edit&id=<?= (int)$row['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                            <a href="?delete=<?= (int)$row['id'] ?>" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($row['agent_name'])) ?>?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<?php
$agent = [];
if ($id > 0) {
    $agent = $db->query("SELECT * FROM fleet_lease_agents WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'New' ?> Lease Agent</h5>
    <a href="fleet_lease_agents.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="POST">
    <input type="hidden" name="save_agent" value="1">
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-person-vcard me-2"></i>Basic Details</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-2">
                    <label class="form-label">Agent Code *</label>
                    <input type="text" name="agent_code" class="form-control" value="<?= htmlspecialchars($agent['agent_code'] ?? '') ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Agent Name *</label>
                    <input type="text" name="agent_name" class="form-control" value="<?= htmlspecialchars($agent['agent_name'] ?? '') ?>" required>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Short Name</label>
                    <input type="text" name="agent_short_name" class="form-control" maxlength="60" value="<?= htmlspecialchars($agent['agent_short_name'] ?? '') ?>" placeholder="Used on challan">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold">Agreement / Billing Model *</label>
                    <select name="billing_model" id="billingModelSelect" class="form-select" onchange="updateBillingModelUI()">
                        <option value="margin_deduction" <?= ($agent['billing_model'] ?? 'margin_deduction') === 'margin_deduction' ? 'selected' : '' ?>>Margin Pass-Through (Model 1)</option>
                        <option value="mgmt_fee" <?= ($agent['billing_model'] ?? '') === 'mgmt_fee' ? 'selected' : '' ?>>Fixed Management Fee (Model 2)</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option <?= (($agent['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>Active</option>
                        <option <?= (($agent['status'] ?? '') === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($agent['contact_person'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold" id="marginRateLabel">Margin / MT (₹)</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" step="0.01" min="0" name="default_margin_per_mt" id="defaultMarginInput" class="form-control" value="<?= htmlspecialchars((string)($agent['default_margin_per_mt'] ?? '0')) ?>">
                        <span class="input-group-text">/ MT</span>
                    </div>
                    <div class="form-text" id="marginRateHelp" style="font-size:.78rem"></div>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($agent['phone'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Mobile</label>
                    <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($agent['mobile'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($agent['email'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="1"><?= htmlspecialchars($agent['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-receipt-cutoff me-2"></i>Tax Settings</div>
        <div class="card-body">
            <?php
                $cur_tax_type = $agent['tax_type'] ?? 'None';
                $cur_tax_rate = (float)($agent['tax_rate'] ?? 0);
                $cur_tax_from_raw = $agent['tax_applicable_from'] ?? '';
                $cur_tax_from = ($cur_tax_from_raw !== '' && $cur_tax_from_raw !== '0000-00-00')
                    ? $cur_tax_from_raw : '';
                $show_tax_fields = ($cur_tax_type !== 'None') ? '' : 'none';
            ?>
            <div class="row g-3 align-items-start">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold">Applicable Tax Mechanism</label>
                    <select name="tax_type" id="taxTypeSelect" class="form-select" onchange="toggleTaxRate()">
                        <option value="None" <?= $cur_tax_type === 'None' ? 'selected' : '' ?>>— None / Not Applicable —</option>
                        <option value="GST"  <?= $cur_tax_type === 'GST'  ? 'selected' : '' ?>>GST &mdash; Goods &amp; Services Tax (Forward Charge)</option>
                        <option value="RCM"  <?= $cur_tax_type === 'RCM'  ? 'selected' : '' ?>>RCM &mdash; Reverse Charge Mechanism</option>
                        <option value="TDS"  <?= $cur_tax_type === 'TDS'  ? 'selected' : '' ?>>TDS &mdash; Tax Deducted at Source</option>
                    </select>
                    <div class="form-text" id="taxTypeHelp"></div>
                </div>
                <div class="col-6 col-md-2" id="taxRateWrap" style="display:<?= $show_tax_fields ?>">
                    <label class="form-label fw-semibold" id="taxRateLabel">Rate (%)</label>
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" max="100" name="tax_rate" id="taxRateInput"
                               class="form-control"
                               value="<?= htmlspecialchars((string)$cur_tax_rate) ?>"
                               placeholder="e.g. 18">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text" id="taxRateHelp"></div>
                </div>
                <div class="col-6 col-md-2" id="taxFromWrap" style="display:<?= $show_tax_fields ?>">
                    <label class="form-label fw-semibold">Applicable From</label>
                    <input type="date" name="tax_applicable_from" id="taxApplicableFrom"
                           class="form-control"
                           value="<?= htmlspecialchars($cur_tax_from) ?>">
                    <div class="form-text">Date this rule takes effect.</div>
                </div>
                <div class="col-12 col-md-4" id="taxInfoWrap" style="display:<?= $show_tax_fields ?>">
                    <div class="alert py-2 mb-0" id="taxInfoBox" style="font-size:.83rem">
                        <i class="bi bi-info-circle me-1"></i><span id="taxInfoText"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    var _taxMeta = {
        None: { help: '',             rateLabel: '',         rateHelp: '',                                         infoClass: 'alert-info',    info: '' },
        GST:  { help: 'GST is levied on the agent payable amount under forward charge — agent raises tax invoice with GST.',
                rateLabel: 'GST Rate (%)',
                rateHelp: 'Standard rates: 5% or 18%. Typically 18% for management service / GTA.',
                infoClass: 'alert-info',
                info: 'Agent will raise a GST invoice. You (service receiver) can claim Input Tax Credit (ITC) if registered.' },
        RCM:  { help: 'Under RCM, the service receiver pays GST directly to govt — agent invoice is without GST.',
                rateLabel: 'GST Rate under RCM (%)',
                rateHelp: 'Typically 5% for GTA under RCM. You pay this directly to GSTN.',
                infoClass: 'alert-warning',
                info: 'You (service receiver) are liable to pay GST under Reverse Charge. Agent does NOT charge GST on invoice.' },
        TDS:  { help: 'TDS is deducted from payment to agent as per Income Tax Act.',
                rateLabel: 'TDS Rate (%)',
                rateHelp: 'Sec 194C: 1% (individual/HUF) or 2% (company/firm). Deduct before payment.',
                infoClass: 'alert-secondary',
                info: 'Deduct TDS before releasing payment. Issue Form 16A. TDS PAN of agent required.' },
    };
    function updateBillingModelUI() {
        var bm = document.getElementById('billingModelSelect') ? document.getElementById('billingModelSelect').value : 'margin_deduction';
        var lbl = document.getElementById('marginRateLabel');
        var hlp = document.getElementById('marginRateHelp');
        if (!lbl) return;
        if (bm === 'mgmt_fee') {
            lbl.textContent = 'Fleet Mgmt Fee / MT (₹)';
            if (hlp) hlp.textContent = 'Fixed fee paid to agent per MT on each trip.';
        } else {
            lbl.textContent = 'Company Margin / MT (₹)';
            if (hlp) hlp.textContent = 'Retained by company from vendor freight.';
        }
    }
    function toggleTaxRate() {
        var type = document.getElementById('taxTypeSelect').value;
        var meta = _taxMeta[type] || _taxMeta.None;
        var wrap     = document.getElementById('taxRateWrap');
        var fromWrap = document.getElementById('taxFromWrap');
        var infoWrap = document.getElementById('taxInfoWrap');
        var show = (type !== 'None');
        wrap.style.display     = show ? '' : 'none';
        fromWrap.style.display = show ? '' : 'none';
        infoWrap.style.display = show ? '' : 'none';
        document.getElementById('taxTypeHelp').textContent = meta.help;
        document.getElementById('taxRateLabel').textContent = meta.rateLabel;
        document.getElementById('taxRateHelp').textContent  = meta.rateHelp;
        var box = document.getElementById('taxInfoBox');
        box.className = 'alert py-2 mb-0 ' + meta.infoClass;
        document.getElementById('taxInfoText').textContent = meta.info;
        if (show && !document.getElementById('taxRateInput').value) {
            document.getElementById('taxRateInput').focus();
        }
    }
    // Run on load
    updateBillingModelUI();
    toggleTaxRate();
    </script>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Agent</button>
        <a href="fleet_lease_agents.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
