<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('company_settings', 'view');

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
// ── CRITICAL: read id from POST on form submit, else from GET ──
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

/* ── Delete ── */
if (isset($_GET['delete'])) {
    if ($db->query("SELECT COUNT(*) c FROM companies")->fetch_assoc()['c'] <= 1) {
        showAlert('danger', 'Cannot delete the only company.');
    } else {
        requirePerm('company_settings', 'update');
        $old = $db->query("SELECT * FROM companies WHERE id=".(int)$_GET['delete']." LIMIT 1")->fetch_assoc();
        foreach (['seal_path','mtc_sig_path','checked_by_sig_path'] as $col) {
            if (!empty($old[$col])) { $f = dirname(__DIR__).'/'.$old[$col]; if(file_exists($f)) unlink($f); }
        }
        $db->query("DELETE FROM companies WHERE id=".(int)$_GET['delete']);
        showAlert('success', 'Company deleted.');
    }
    redirect('companies.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('company_settings', 'update');

    // Safety guard — block save if no id and not explicitly adding
    if ($id === 0 && $action !== 'add') {
        showAlert('danger', 'Invalid request — company ID missing. Please use the Edit button.');
        redirect('companies.php');
    }

    $fields = ['company_name','address','city','state','pincode','phone','email','gstin','pan',
               'bank_name','account_no','ifsc_code'];
    $data = [];
    foreach ($fields as $f) $data[$f] = sanitize($_POST[$f] ?? '');

    if (empty($data['company_name'])) {
        showAlert('danger', 'Company name is required.');
        redirect('companies.php?action='.($id>0?'edit&id='.$id:'add'));
    }

    $img_dir = dirname(__DIR__) . '/uploads/company/';
    if (!is_dir($img_dir)) @mkdir($img_dir, 0755, true);
    $existing = $id > 0 ? $db->query("SELECT * FROM companies WHERE id=$id LIMIT 1")->fetch_assoc() : null;

    foreach (['seal_path'=>'seal','mtc_sig_path'=>'mtc_sig','checked_by_sig_path'=>'checked_by_sig'] as $col=>$inp) {
        if (!empty($_POST['delete_'.$inp]) && !empty($existing[$col])) {
            $f = dirname(__DIR__).'/'.$existing[$col]; if(file_exists($f)) unlink($f);
            $data[$col] = 'NULL_MARKER';
        }
        if (!empty($_FILES[$inp]['name']) && $_FILES[$inp]['error']===UPLOAD_ERR_OK) {
            $ext   = strtolower(pathinfo($_FILES[$inp]['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg','jpeg','png','gif','webp'];
            $info  = @getimagesize($_FILES[$inp]['tmp_name']);
            $valid = ($info && in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_GIF,IMAGETYPE_WEBP]))
                  || in_array($ext, $allowed_ext); // fallback by extension
            if ($valid) {
                if (!empty($existing[$col])) { $f=dirname(__DIR__).'/'.$existing[$col]; if(file_exists($f)) unlink($f); }
                $fname = $inp.'_'.time().'.'.$ext;
                if (move_uploaded_file($_FILES[$inp]['tmp_name'], $img_dir.$fname)) {
                    $data[$col] = 'uploads/company/'.$fname;
                } else {
                    dmsLogError('WARNING', "Failed to move uploaded file for $col to $img_dir$fname");
                }
            } else {
                dmsLogError('WARNING', "Invalid image type for $col — file: ".$_FILES[$inp]['name']);
            }
        }
    }

    if ($id > 0) {
        if (!$db->query("SELECT id FROM companies WHERE id=$id LIMIT 1")->fetch_assoc()) {
            showAlert('danger', 'Company not found.');
            redirect('companies.php');
        }
        // Auto-add image columns if missing
        $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
        foreach (['seal_path','mtc_sig_path','checked_by_sig_path'] as $imgcol) {
            $col_exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='companies'
                AND COLUMN_NAME='$imgcol' LIMIT 1")->num_rows;
            if (!$col_exists) {
                $db->query("ALTER TABLE companies ADD COLUMN `$imgcol` VARCHAR(255) DEFAULT NULL");
            }
        }
        $parts = [];
        foreach ($data as $k => $v) {
            if ($v === 'NULL_MARKER') $parts[] = "$k=NULL";
            else $parts[] = "$k='".$db->real_escape_string($v)."'";
        }
        $sql = "UPDATE companies SET ".implode(',',$parts)." WHERE id=$id";
        $db->query($sql);
        showAlert('success', 'Company updated successfully.');
        redirect('companies.php');
    } else {
        $cols2=[]; $vals2=[];
        foreach ($data as $k=>$v) {
            if ($v !== 'NULL_MARKER') { $cols2[]=$k; $vals2[]="'".$db->real_escape_string($v)."'"; }
        }
        $db->query("INSERT INTO companies (".implode(',',$cols2).") VALUES (".implode(',',$vals2).")");
        $new_id = $db->insert_id;
        showAlert('success', 'Company added successfully.');
        redirect('companies.php');
    }
}

$edit_company = $id > 0 ? ($db->query("SELECT * FROM companies WHERE id=$id LIMIT 1")->fetch_assoc() ?? []) : [];
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-buildings me-2"></i>Companies';</script>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">All Companies</h5>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Company</a>
</div>
<div class="card"><div class="card-body p-0">
<table class="table table-hover datatable mb-0">
<thead><tr><th>#</th><th>Company Name</th><th>GSTIN</th><th>City</th><th>Phone</th><th>Actions</th></tr></thead>
<tbody>
<?php $list=$db->query("SELECT * FROM companies ORDER BY id"); $i=1;
while ($co=$list->fetch_assoc()): $isActive=(activeCompanyId()===(int)$co['id']); ?>
<tr class="<?= $isActive?'table-success':'' ?>">
    <td><?= $i++ ?></td>
    <td><strong><?= htmlspecialchars($co['company_name']) ?></strong>
        <?php if($isActive): ?><span class="badge bg-success ms-1">Active</span><?php endif; ?></td>
    <td><code><?= htmlspecialchars($co['gstin']??'-') ?></code></td>
    <td><?= htmlspecialchars($co['city']??'-') ?></td>
    <td><?= htmlspecialchars($co['phone']??'-') ?></td>
    <td>
        <a href="switch_company.php?id=<?= $co['id'] ?>&ret=companies.php" class="btn btn-action btn-outline-success me-1" title="Switch"><i class="bi bi-arrow-repeat"></i></a>
        <a href="?action=edit&id=<?= $co['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <button onclick="confirmDelete(<?= $co['id'] ?>,'companies.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
    </td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div></div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $action==='edit'?'Edit':'Add' ?> Company</h5>
    <a href="companies.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST" enctype="multipart/form-data">
<!-- CRITICAL: carry company id through POST so save knows which record to UPDATE -->
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">
<div class="row g-3">

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-building me-2"></i>Company Information</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-6">
        <label class="form-label">Company Name *</label>
        <input type="text" name="company_name" class="form-control" required value="<?= htmlspecialchars($edit_company['company_name']??'') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($edit_company['phone']??'') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($edit_company['email']??'') ?>">
    </div>
    <div class="col-12 col-md-8">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($edit_company['address']??'') ?></textarea>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">City</label>
        <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($edit_company['city']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">State</label>
        <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($edit_company['state']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Pincode</label>
        <input type="text" name="pincode" class="form-control" value="<?= htmlspecialchars($edit_company['pincode']??'') ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">GSTIN</label>
        <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= htmlspecialchars($edit_company['gstin']??'') ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">PAN</label>
        <input type="text" name="pan" class="form-control" maxlength="10" value="<?= htmlspecialchars($edit_company['pan']??'') ?>">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-bank me-2"></i>Bank Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-md-5"><label class="form-label">Bank Name</label>
        <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($edit_company['bank_name']??'') ?>"></div>
    <div class="col-md-4"><label class="form-label">Account Number</label>
        <input type="text" name="account_no" class="form-control" value="<?= htmlspecialchars($edit_company['account_no']??'') ?>"></div>
    <div class="col-md-3"><label class="form-label">IFSC Code</label>
        <input type="text" name="ifsc_code" class="form-control" value="<?= htmlspecialchars($edit_company['ifsc_code']??'') ?>"></div>
</div></div></div></div>



<div class="col-12"><div class="card">
<div class="card-header"><i class="bi bi-image me-2"></i>Company Stamps &amp; Signatures</div>
<div class="card-body"><div class="row g-4">
<?php
$img_uploads = [
    ['col'=>'seal_path',           'input'=>'seal',           'label'=>'Company Seal',                    'hint'=>'Shown in Company Seal box on challan &amp; MTC'],
    ['col'=>'mtc_sig_path',        'input'=>'mtc_sig',        'label'=>'MTC Manager Technical Signature', 'hint'=>'Shown in Manager Technical signature box on MTC'],
    ['col'=>'checked_by_sig_path', 'input'=>'checked_by_sig', 'label'=>'Checked By Signature',            'hint'=>'Shown above Checked By label on challan'],
];
foreach ($img_uploads as $iu):
    $cur_path = $edit_company[$iu['col']] ?? '';
    $cur_url  = $cur_path ? '../'.$cur_path : '';
?>
<div class="col-12 col-md-4">
<div class="card h-100 border"><div class="card-header bg-light py-2"><strong><?= $iu['label'] ?></strong></div>
<div class="card-body text-center">
    <div class="img-preview-wrap mb-2" style="min-height:80px;border:2px dashed #ccc;border-radius:6px;background:#f8f9fa;display:flex;align-items:center;justify-content:center;padding:8px">
        <?php if ($cur_url): ?>
        <img src="<?= htmlspecialchars($cur_url) ?>" style="max-height:70px;max-width:100%;object-fit:contain">
        <?php else: ?><span class="text-muted small"><i class="bi bi-image fs-3 d-block mb-1"></i>Not uploaded</span><?php endif; ?>
    </div>
    <input type="file" name="<?= $iu['input'] ?>" class="form-control form-control-sm mb-1"
           accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewImg(this)">
    <div class="form-text text-start"><?= $iu['hint'] ?></div>
    <?php if ($cur_url): ?>
    <label class="form-check mt-2 text-start">
        <input type="checkbox" name="delete_<?= $iu['input'] ?>" value="1" class="form-check-input">
        <span class="form-check-label text-danger small">Remove current image</span>
    </label>
    <?php endif; ?>
</div></div>
</div>
<?php endforeach; ?>
</div></div>
</div></div>

<div class="col-12 text-end">
    <a href="companies.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Company</button>
</div>
</div>
</form>
<script>
function previewImg(input) {
    var wrap = input.previousElementSibling;
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) { wrap.innerHTML = '<img src="'+e.target.result+'" style="max-height:70px;max-width:100%;object-fit:contain">'; };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
