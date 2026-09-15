<?php
/**
 * Quotations Module — List View
 * Path: modules/quotations/index.php
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
requirePerm('quotations', 'view');

$db = getDB();
$pageTitle = 'Quotations';

// Filters
$status_filter = sanitize($_GET['status'] ?? '');
$search        = sanitize($_GET['q'] ?? '');

$where  = ["1=1"];
$params = [];
$types  = '';

if ($status_filter) {
    $where[]  = "q.status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}
if ($search) {
    $like     = '%' . $search . '%';
    $where[]  = "(q.quotation_no LIKE ? OR q.client_name LIKE ? OR q.client_company LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}

$whereStr = implode(' AND ', $where);
$sql = "SELECT q.*, u.full_name AS created_by_name
        FROM quotations q
        LEFT JOIN app_users u ON u.id = q.created_by
        WHERE $whereStr
        ORDER BY q.created_at DESC";

$stmt = $db->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$quotations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary counts
$counts = [];
$res = $db->query("SELECT status, COUNT(*) as cnt FROM quotations GROUP BY status");
while ($row = $res->fetch_assoc()) $counts[$row['status']] = $row['cnt'];

require_once '../includes/header.php';
require_once 'quot_helpers.php';
?>

<div class="container-fluid py-3">

  <!-- Page Header -->
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h5 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text-fill text-purple me-2"></i>Quotations</h5>
      <small class="text-muted">Manage client quotations for TSG Impex India Pvt Ltd</small>
    </div>
    <?php if (canDo('quotations', 'create')): ?>
    <a href="quot_create.php" class="btn btn-sm btn-purple">
      <i class="bi bi-plus-circle me-1"></i> New Quotation
    </a>
    <?php endif; ?>
  </div>

  <!-- Status Summary Cards -->
  <div class="row g-2 mb-3">
    <?php
    $statCards = [
      'draft'    => ['Draft',    'secondary', 'bi-pencil-square'],
      'sent'     => ['Sent',     'primary',   'bi-send'],
      'accepted' => ['Accepted', 'success',   'bi-check-circle'],
      'rejected' => ['Rejected', 'danger',    'bi-x-circle'],
      'expired'  => ['Expired',  'warning',   'bi-clock-history'],
    ];
    foreach ($statCards as $key => [$label, $color, $icon]):
      $cnt = $counts[$key] ?? 0;
    ?>
    <div class="col-4 col-md-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body p-2 text-center">
          <i class="bi <?= $icon ?> text-<?= $color ?> fs-5"></i>
          <div class="fw-bold fs-5"><?= $cnt ?></div>
          <div class="text-muted small"><?= $label ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
          <input type="text" name="q" class="form-control form-control-sm"
                 placeholder="Search by No / Client / Company..."
                 value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-3">
          <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <?php foreach ($statCards as $key => [$label, ,]): ?>
            <option value="<?= $key ?>" <?= $status_filter === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-sm btn-outline-purple" type="submit"><i class="bi bi-search"></i> Filter</button>
          <a href="quot_index.php" class="btn btn-sm btn-outline-secondary ms-1">Clear</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Table -->
  <div class="card shadow-sm border-0">
    <div class="card-body p-0">
      <!-- Desktop table -->
      <div class="d-none d-md-block table-responsive">
        <table id="quotationsTable" class="table table-hover align-middle mb-0 small">
          <thead class="table-dark">
            <tr>
              <th>Quot. No.</th>
              <th>Date</th>
              <th>Client</th>
              <th>Company</th>
              <th>Valid Till</th>
              <th>Status</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($quotations)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No quotations found.</td></tr>
            <?php endif; ?>
            <?php foreach ($quotations as $q): ?>
            <tr>
              <td><a href="quot_view.php?id=<?= $q['id'] ?>" class="fw-semibold text-decoration-none text-purple">
                <?= htmlspecialchars($q['quotation_no']) ?>
              </a></td>
              <td><?= date('d M Y', strtotime($q['quotation_date'])) ?></td>
              <td><?= htmlspecialchars($q['client_name']) ?></td>
              <td><?= htmlspecialchars($q['client_company']) ?></td>
              <td><?= date('d M Y', strtotime($q['valid_till'])) ?></td>
              <td><?= quotationStatusBadge($q['status']) ?></td>
              <td class="text-center">
                <a href="quot_view.php?id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                <?php if (canDo('quotations', 'edit') && in_array($q['status'], ['draft', 'sent'])): ?>
                <a href="quot_edit.php?id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <?php if (canDo('quotations', 'delete') && $q['status'] === 'draft'): ?>
                <button class="btn btn-sm btn-outline-danger" title="Delete"
                        onclick="confirmDelete(<?= $q['id'] ?>, '<?= htmlspecialchars($q['quotation_no']) ?>')">
                  <i class="bi bi-trash"></i>
                </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile card list -->
      <div class="d-md-none">
        <?php if (empty($quotations)): ?>
        <div class="text-center text-muted py-4 small">No quotations found.</div>
        <?php endif; ?>
        <?php foreach ($quotations as $q): ?>
        <div class="border-bottom px-3 py-2">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <a href="quot_view.php?id=<?= $q['id'] ?>" class="fw-bold text-purple text-decoration-none small">
                <?= htmlspecialchars($q['quotation_no']) ?>
              </a>
              <?= quotationStatusBadge($q['status']) ?>
            </div>
            <div class="d-flex gap-1">
              <a href="quot_view.php?id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1"><i class="bi bi-eye"></i></a>
              <?php if (canDo('quotations', 'edit') && in_array($q['status'], ['draft', 'sent'])): ?>
              <a href="quot_edit.php?id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-warning py-0 px-1"><i class="bi bi-pencil"></i></a>
              <?php endif; ?>
              <?php if (canDo('quotations', 'delete') && $q['status'] === 'draft'): ?>
              <button class="btn btn-sm btn-outline-danger py-0 px-1"
                      onclick="confirmDelete(<?= $q['id'] ?>, '<?= htmlspecialchars($q['quotation_no']) ?>')">
                <i class="bi bi-trash"></i>
              </button>
              <?php endif; ?>
            </div>
          </div>
          <div class="small text-muted mt-1">
            <strong class="text-dark"><?= htmlspecialchars($q['client_name']) ?></strong>
            · <?= htmlspecialchars($q['client_company']) ?>
          </div>
          <div class="small text-muted">
            <?= date('d M Y', strtotime($q['quotation_date'])) ?>
            · Valid till <?= date('d M Y', strtotime($q['valid_till'])) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Delete confirm modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white py-2">
        <h6 class="modal-title mb-0">Delete Quotation</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body small">
        Are you sure you want to delete <strong id="deleteQuotNo"></strong>? This cannot be undone.
      </div>
      <div class="modal-footer py-2">
        <form method="POST" action="quot_delete.php">
          <input type="hidden" name="id" id="deleteQuotId">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-danger ms-1">Delete</button>
        </form>
      </div>
    </div>
  </div>
</div>

<style>
.text-purple { color: #6f1f8a !important; }
.btn-purple { background:#6f1f8a; color:#fff; border-color:#6f1f8a; }
.btn-purple:hover { background:#5a1870; color:#fff; }
.btn-outline-purple { color:#6f1f8a; border-color:#6f1f8a; }
.btn-outline-purple:hover { background:#6f1f8a; color:#fff; }
</style>

<script>
function confirmDelete(id, no) {
    document.getElementById('deleteQuotId').value = id;
    document.getElementById('deleteQuotNo').textContent = no;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof DataTable !== 'undefined' && <?= count($quotations) ?> > 0) {
        new DataTable('#quotationsTable', {
            paging: true, searching: false, ordering: true,
            order: [[1, 'desc']], pageLength: 25,
            columnDefs: [{ orderable: false, targets: 6 }]
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; $db->close(); ?>
