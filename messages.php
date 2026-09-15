<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/webpush_helper.php';

if (empty($_SESSION['user_id'])) { redirect('login.php'); }

$db = getDB();
$current_user_id = $_SESSION['user_id'];
$current_user_name = $_SESSION['full_name'] ?? $_SESSION['username'];
$db->set_charset('utf8mb4');

// ── Create tables ────────────────────────────────────────────────
$db->query("CREATE TABLE IF NOT EXISTS `dms_messages` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `sender_id`   INT NOT NULL,
    `recipient_id` INT NOT NULL,
    `subject`     VARCHAR(255) NOT NULL DEFAULT '(No Subject)',
    `body`        TEXT NOT NULL,
    `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_by_sender`    TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_by_recipient` TINYINT(1) NOT NULL DEFAULT 0,
    `attachment_key`  VARCHAR(255) NOT NULL DEFAULT '',
    `attachment_name` VARCHAR(255) NOT NULL DEFAULT '',
    `attachment_size` INT NOT NULL DEFAULT 0,
    `attachment_mime` VARCHAR(120) NOT NULL DEFAULT '',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(`recipient_id`),
    INDEX(`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Safe migration for attachment columns on old installs.
$dbName = $db->query("SELECT DATABASE()")->fetch_row()[0];
foreach ([
    'attachment_key'  => "VARCHAR(255) NOT NULL DEFAULT ''",
    'attachment_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'attachment_size' => "INT NOT NULL DEFAULT 0",
    'attachment_mime' => "VARCHAR(120) NOT NULL DEFAULT ''",
] as $col => $def) {
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='dms_messages' AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE dms_messages ADD COLUMN `$col` $def");
}

// ── Action handling ──────────────────────────────────────────────
$action = $_GET['action'] ?? 'inbox';

// SEND message (supports multiple recipients)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $subject = trim((string)($_POST['subject'] ?? ''));
    if ($subject === '') $subject = '(No Subject)';
    $body = (string)($_POST['body'] ?? '');
    // Preserve intentional line breaks and normalize CRLF/CR to LF.
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = trim($body);

    // Collect recipient IDs — 'all' keyword or array of IDs
    $raw_recipients = $_POST['recipient_ids'] ?? [];
    $recipient_ids  = [];

    if (in_array('all', (array)$raw_recipients)) {
        // Send to every active user except sender
        $res = $db->query("SELECT id FROM app_users WHERE id != $current_user_id AND status = 'Active'");
        if ($res) while ($r = $res->fetch_assoc()) $recipient_ids[] = (int)$r['id'];
    } else {
        foreach ((array)$raw_recipients as $rid) {
            $rid = (int)$rid;
            if ($rid > 0 && $rid !== $current_user_id) $recipient_ids[] = $rid;
        }
        $recipient_ids = array_unique($recipient_ids);
    }

    $attachment_key = '';
    $attachment_name = '';
    $attachment_size = 0;
    $attachment_mime = '';
    if (!empty($_FILES['message_attachment']['name']) && (int)($_FILES['message_attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['message_attachment'];
        if ((int)$f['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','jpg','jpeg','png','webp','gif','txt','csv','doc','docx','xls','xlsx'];
            $attachment_size = (int)($f['size'] ?? 0);
            if (!in_array($ext, $allowed, true)) {
                showAlert('danger', 'Attachment type not allowed.');
                redirect('messages.php?action=inbox');
            }
            if ($attachment_size > 10 * 1024 * 1024) {
                showAlert('danger', 'Attachment must be 10MB or less.');
                redirect('messages.php?action=inbox');
            }
            $mimeMap = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
            $attachment_mime = $mimeMap[$ext] ?? 'application/octet-stream';
            $attachment_name = basename((string)$f['name']);
            $attachment_key = 'messages/M' . $current_user_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!function_exists('r2_upload') || !r2_upload((string)$f['tmp_name'], $attachment_key, $attachment_mime)) {
                showAlert('danger', 'Attachment upload failed. Please try again.');
                redirect('messages.php?action=inbox');
            }
        } else {
            showAlert('danger', 'Attachment upload failed.');
            redirect('messages.php?action=inbox');
        }
    }

    if (!empty($recipient_ids) && $body !== '') {
        $stmt = $db->prepare("INSERT INTO dms_messages (sender_id, recipient_id, subject, body, attachment_key, attachment_name, attachment_size, attachment_mime) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($recipient_ids as $rid) {
            $stmt->bind_param("iissssis", $current_user_id, $rid, $subject, $body, $attachment_key, $attachment_name, $attachment_size, $attachment_mime);
            $stmt->execute();
            dmsSendWebPushToUser($db, (int)$rid);
        }
        $stmt->close();
        $count = count($recipient_ids);
        showAlert('success', $count === 1 ? 'Message sent successfully.' : "Message sent to $count users.");
        redirect('messages.php?action=sent');
    } else {
        showAlert('danger', 'Please select at least one recipient and enter a message.');
    }
}
// MARK READ
if ($action === 'read' && isset($_GET['id'])) {
    $msg_id = (int)$_GET['id'];
    $db->query("UPDATE dms_messages SET is_read=1 WHERE id=$msg_id AND recipient_id=$current_user_id");
    // fall through to display
}

// DELETE
if ($action === 'delete' && isset($_GET['id'])) {
    $msg_id = (int)$_GET['id'];
    $box    = $_GET['box'] ?? 'inbox';
    if ($box === 'sent') {
        $db->query("UPDATE dms_messages SET deleted_by_sender=1 WHERE id=$msg_id AND sender_id=$current_user_id");
    } else {
        $db->query("UPDATE dms_messages SET deleted_by_recipient=1 WHERE id=$msg_id AND recipient_id=$current_user_id");
    }
    showAlert('success', 'Message deleted.');
    redirect("messages.php?action=$box");
}

// FETCH single message for view
$view_message = null;
if ($action === 'read' && isset($_GET['id'])) {
    $msg_id = (int)$_GET['id'];
    $stmt = $db->prepare("
        SELECT m.*, 
               COALESCE(s.full_name, s.username) AS sender_name, s.username AS sender_username,
               COALESCE(r.full_name, r.username) AS recipient_name, r.username AS recipient_username
        FROM dms_messages m
        JOIN app_users s ON s.id = m.sender_id
        JOIN app_users r ON r.id = m.recipient_id
        WHERE m.id = ?
          AND (m.recipient_id = ? OR m.sender_id = ?)
    ");
    $stmt->bind_param("iii", $msg_id, $current_user_id, $current_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $view_message = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

// FETCH inbox messages
$inbox_messages = [];
if ($action === 'inbox' || $action === 'read') {
    $result = $db->query("
        SELECT m.id, m.subject, m.body, m.is_read, m.created_at, m.attachment_key, m.attachment_name,
               COALESCE(u.full_name, u.username) AS sender_name, u.username AS sender_username
        FROM dms_messages m
        JOIN app_users u ON u.id = m.sender_id
        WHERE m.recipient_id = $current_user_id
          AND m.deleted_by_recipient = 0
        ORDER BY m.created_at DESC
    ");
    if ($result) while ($row = $result->fetch_assoc()) $inbox_messages[] = $row;
}

// FETCH sent messages
$sent_messages = [];
if ($action === 'sent') {
    $result = $db->query("
        SELECT m.id, m.subject, m.body, m.is_read, m.created_at, m.attachment_key, m.attachment_name,
               COALESCE(u.full_name, u.username) AS recipient_name, u.username AS recipient_username
        FROM dms_messages m
        JOIN app_users u ON u.id = m.recipient_id
        WHERE m.sender_id = $current_user_id
          AND m.deleted_by_sender = 0
        ORDER BY m.created_at DESC
    ");
    if ($result) while ($row = $result->fetch_assoc()) $sent_messages[] = $row;
}

// FETCH users for compose dropdown
$all_users = [];
$result = $db->query("SELECT id, COALESCE(full_name, username) AS display_name, username FROM app_users WHERE id != $current_user_id AND status = 'Active' ORDER BY display_name");
if ($result) while ($row = $result->fetch_assoc()) $all_users[] = $row;

// Unread count
$unread_res   = $db->query("SELECT COUNT(*) AS cnt FROM dms_messages WHERE recipient_id=$current_user_id AND is_read=0 AND deleted_by_recipient=0");
$unread_count = $unread_res ? (int)$unread_res->fetch_assoc()['cnt'] : 0;

// Pre-fill reply
$reply_to_id      = (int)($_GET['reply_to'] ?? 0);
$reply_subject    = '';
$reply_recipient  = 0;
if ($reply_to_id && $view_message) {
    $reply_recipient = $view_message['sender_id'];
    $reply_subject   = 'Re: ' . $view_message['subject'];
}

include 'includes/header.php';
?>

<div class="container-fluid py-3">

  <!-- Page header -->
  <div class="d-flex align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-chat-dots-fill text-primary me-2"></i>Messages
      <?php if ($unread_count > 0): ?>
        <span class="badge bg-danger ms-1"><?= $unread_count ?></span>
      <?php endif; ?>
    </h4>
    <div class="ms-auto">
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#composeModal">
        <i class="bi bi-pencil-square me-1"></i>Compose
      </button>
    </div>
  </div>

  <div class="row g-3">

    <!-- Sidebar nav -->
    <div class="col-md-2">
      <div class="list-group list-group-flush shadow-sm rounded">
        <a href="messages.php?action=inbox"
           class="list-group-item list-group-item-action <?= $action === 'inbox' || $action === 'read' ? 'active' : '' ?>">
          <i class="bi bi-inbox me-1"></i> Inbox
          <?php if ($unread_count > 0): ?>
            <span class="badge bg-danger float-end"><?= $unread_count ?></span>
          <?php endif; ?>
        </a>
        <a href="messages.php?action=sent"
           class="list-group-item list-group-item-action <?= $action === 'sent' ? 'active' : '' ?>">
          <i class="bi bi-send me-1"></i> Sent
        </a>
      </div>
    </div>

    <!-- Main area -->
    <div class="col-md-10">

      <?php if ($action === 'inbox' || ($action === 'read' && !$view_message)): ?>
      <!-- INBOX -->
      <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold">Inbox</div>
        <div class="card-body p-0">
          <?php if (empty($inbox_messages)): ?>
            <p class="text-muted p-3 mb-0">No messages in your inbox.</p>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:30px"></th>
                  <th>From</th>
                  <th>Subject</th>
                  <th>Date</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($inbox_messages as $msg): ?>
                <tr class="<?= !$msg['is_read'] ? 'fw-bold' : '' ?>">
                  <td><?= !$msg['is_read'] ? '<span class="badge bg-primary">New</span>' : '' ?></td>
                  <td><?= htmlspecialchars($msg['sender_name'] ?: $msg['sender_username']) ?></td>
                  <td>
                    <a href="messages.php?action=read&id=<?= $msg['id'] ?>" class="text-decoration-none text-dark">
                      <?= htmlspecialchars($msg['subject']) ?>
                    </a>
                    <?php if (!empty($msg['attachment_key'])): ?>
                    <span class="badge bg-light text-dark border ms-1"><i class="bi bi-paperclip"></i></span>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted small"><?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?></td>
                  <td class="text-end">
                    <a href="messages.php?action=delete&id=<?= $msg['id'] ?>&box=inbox"
                       class="btn btn-sm btn-outline-danger"
                       onclick="return confirm('Delete this message?')">
                      <i class="bi bi-trash"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php elseif ($action === 'sent'): ?>
      <!-- SENT -->
      <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold">Sent Messages</div>
        <div class="card-body p-0">
          <?php if (empty($sent_messages)): ?>
            <p class="text-muted p-3 mb-0">No sent messages.</p>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>To</th>
                  <th>Subject</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($sent_messages as $msg): ?>
                <tr>
                  <td><?= htmlspecialchars($msg['recipient_name'] ?: $msg['recipient_username']) ?></td>
                  <td>
                    <a href="messages.php?action=read&id=<?= $msg['id'] ?>" class="text-decoration-none text-dark">
                      <?= htmlspecialchars($msg['subject']) ?>
                    </a>
                    <?php if (!empty($msg['attachment_key'])): ?>
                    <span class="badge bg-light text-dark border ms-1"><i class="bi bi-paperclip"></i></span>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted small"><?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?></td>
                  <td><?= $msg['is_read'] ? '<span class="badge bg-success">Read</span>' : '<span class="badge bg-secondary">Unread</span>' ?></td>
                  <td class="text-end">
                    <a href="messages.php?action=delete&id=<?= $msg['id'] ?>&box=sent"
                       class="btn btn-sm btn-outline-danger"
                       onclick="return confirm('Delete this message?')">
                      <i class="bi bi-trash"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php elseif ($action === 'read' && $view_message): ?>
      <!-- READ MESSAGE -->
      <div class="card shadow-sm">
        <div class="card-header bg-white d-flex align-items-center">
          <a href="messages.php?action=inbox" class="btn btn-sm btn-outline-secondary me-2">
            <i class="bi bi-arrow-left"></i> Back
          </a>
          <span class="fw-semibold"><?= htmlspecialchars($view_message['subject']) ?></span>
        </div>
        <div class="card-body">
          <div class="mb-3 pb-3 border-bottom">
            <div class="row">
              <div class="col-sm-6">
                <small class="text-muted">From:</small>
                <strong class="ms-2"><?= htmlspecialchars($view_message['sender_name'] ?: $view_message['sender_username']) ?></strong>
              </div>
              <div class="col-sm-6 text-sm-end">
                <small class="text-muted">To:</small>
                <strong class="ms-2"><?= htmlspecialchars($view_message['recipient_name'] ?: $view_message['recipient_username']) ?></strong>
              </div>
            </div>
            <div class="mt-1">
              <small class="text-muted"><?= date('d M Y, h:i A', strtotime($view_message['created_at'])) ?></small>
            </div>
          </div>
          <div class="message-body" style="white-space:pre-wrap; line-height:1.7;"><?= htmlspecialchars($view_message['body']) ?></div>
          <?php if (!empty($view_message['attachment_key'])): ?>
          <div class="mt-3">
            <a href="<?= htmlspecialchars(r2_url($view_message['attachment_key'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-paperclip me-1"></i>Attachment: <?= htmlspecialchars($view_message['attachment_name'] ?: 'Open file') ?>
            </a>
          </div>
          <?php endif; ?>
        </div>
        <div class="card-footer bg-white d-flex gap-2">
          <?php if ($view_message['sender_id'] != $current_user_id): ?>
          <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#composeModal"
                  data-recipient="<?= $view_message['sender_id'] ?>"
                  data-recipient-name="<?= htmlspecialchars($view_message['sender_name'] ?: $view_message['sender_username']) ?>"
                  data-subject="Re: <?= htmlspecialchars($view_message['subject']) ?>">
            <i class="bi bi-reply me-1"></i>Reply
          </button>
          <?php endif; ?>
          <?php $delete_box = ($view_message['sender_id'] == $current_user_id) ? 'sent' : 'inbox'; ?>
          <a href="messages.php?action=delete&id=<?= $view_message['id'] ?>&box=<?= $delete_box ?>"
             class="btn btn-outline-danger btn-sm"
             onclick="return confirm('Delete this message?')">
            <i class="bi bi-trash me-1"></i>Delete
          </a>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Compose Modal -->
<div class="modal fade" id="composeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" action="messages.php" id="composeForm" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i>Compose Message</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

          <!-- Recipients -->
          <div class="mb-3">
            <label class="form-label fw-semibold">To <span class="text-danger">*</span></label>
            <div id="recipientTags" class="d-flex flex-wrap gap-1 mb-2" style="min-height:0"></div>
            <div class="input-group">
              <input type="text" id="recipientSearch" class="form-control" placeholder="Search and select users…" autocomplete="off">
              <button type="button" class="btn btn-outline-danger" id="btnAllUsers">
                <i class="bi bi-people-fill me-1"></i>All Users
              </button>
            </div>
            <div id="recipientDropdown" class="border rounded mt-1 bg-white shadow-sm" style="display:none;max-height:180px;overflow-y:auto;z-index:10;position:relative">
              <?php foreach ($all_users as $u): ?>
              <div class="recipient-option px-3 py-2 d-flex align-items-center gap-2"
                   style="cursor:pointer"
                   data-id="<?= $u['id'] ?>"
                   data-name="<?= htmlspecialchars($u['display_name'] ?: $u['username']) ?>">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle text-white fw-bold flex-shrink-0"
                      style="width:26px;height:26px;font-size:.7rem;background:linear-gradient(135deg,#1a5632,#27ae60)">
                  <?= strtoupper(substr($u['display_name'] ?: $u['username'], 0, 1)) ?>
                </span>
                <span><?= htmlspecialchars($u['display_name'] ?: $u['username']) ?></span>
                <small class="text-muted ms-auto">@<?= htmlspecialchars($u['username']) ?></small>
              </div>
              <?php endforeach; ?>
            </div>
            <div id="recipientInputs"></div>
            <div id="recipientError" class="text-danger small mt-1" style="display:none">Please select at least one recipient.</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Subject</label>
            <input type="text" name="subject" id="subjectInput" class="form-control" placeholder="Subject" value="">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
            <textarea name="body" class="form-control" rows="7" placeholder="Type your message here…" required></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Attachment (optional)</label>
            <input type="file" name="message_attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.txt,.csv,.doc,.docx,.xls,.xlsx">
            <div class="form-text">Max 10MB. Allowed: PDF, image, text, Word, Excel.</div>
          </div>
        </div>
        <div class="modal-footer justify-content-between">
          <span id="recipientCount" class="text-muted small"></span>
          <div>
            <button type="button" class="btn btn-secondary me-1" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="send_message" class="btn btn-primary">
              <i class="bi bi-send me-1"></i>Send
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.recipient-option:hover { background: #f0f8f3; }
.recipient-option.selected { background: #e8f5e9; }
.recipient-tag {
  display:inline-flex;align-items:center;gap:5px;
  background:#e8f5e9;border:1px solid #a5d6a7;
  border-radius:20px;padding:3px 10px;font-size:.82rem;font-weight:500;
}
.recipient-tag.all-tag { background:#fff3e0;border-color:#ffcc80; }
.recipient-tag button { background:none;border:none;padding:0;line-height:1;font-size:.95rem;color:#666;cursor:pointer; }
.recipient-tag button:hover { color:#c00; }
</style>

<script>
(function () {
  var allUsersData = <?php echo json_encode(array_values($all_users)); ?>;
  var selected = {};
  var isAll = false;

  var tags      = document.getElementById('recipientTags');
  var inputsEl  = document.getElementById('recipientInputs');
  var search    = document.getElementById('recipientSearch');
  var dropdown  = document.getElementById('recipientDropdown');
  var btnAll    = document.getElementById('btnAllUsers');
  var countLbl  = document.getElementById('recipientCount');
  var errLbl    = document.getElementById('recipientError');
  var options   = document.querySelectorAll('.recipient-option');

  function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function render() {
    tags.innerHTML = ''; inputsEl.innerHTML = '';
    if (isAll) {
      tags.innerHTML = '<span class="recipient-tag all-tag"><i class="bi bi-people-fill me-1"></i>All Users <button type="button" onclick="window._rmAll()">×</button></span>';
      inputsEl.innerHTML = '<input type="hidden" name="recipient_ids[]" value="all">';
      countLbl.textContent = 'Will send to all ' + allUsersData.length + ' users';
    } else {
      var ids = Object.keys(selected);
      ids.forEach(function(id){
        var t = document.createElement('span');
        t.className = 'recipient-tag';
        t.innerHTML = esc(selected[id]) + ' <button type="button" onclick="window._rmOne('+id+')">×</button>';
        tags.appendChild(t);
        var inp = document.createElement('input');
        inp.type='hidden'; inp.name='recipient_ids[]'; inp.value=id;
        inputsEl.appendChild(inp);
      });
      countLbl.textContent = ids.length > 0 ? ids.length + ' recipient'+(ids.length>1?'s':'')+' selected' : '';
    }
    options.forEach(function(o){ o.classList.toggle('selected', !isAll && !!selected[o.dataset.id]); });
    btnAll.classList.toggle('btn-danger', isAll);
    btnAll.classList.toggle('btn-outline-danger', !isAll);
  }

  window._rmAll = function(){ isAll=false; render(); };
  window._rmOne = function(id){ delete selected[id]; render(); };

  btnAll.addEventListener('click', function(){
    isAll = !isAll;
    if(isAll) selected={};
    render();
    dropdown.style.display='none';
    search.value='';
  });

  search.addEventListener('focus', function(){ if(allUsersData.length) dropdown.style.display=''; });
  search.addEventListener('input', function(){
    var q = this.value.toLowerCase();
    var any=false;
    options.forEach(function(o){
      var show = o.dataset.name.toLowerCase().includes(q);
      o.style.display = show?'':'none';
      if(show) any=true;
    });
    dropdown.style.display = any?'':'none';
  });
  document.addEventListener('click', function(e){
    if(!search.contains(e.target)&&!dropdown.contains(e.target)&&!btnAll.contains(e.target))
      dropdown.style.display='none';
  });

  options.forEach(function(o){
    o.addEventListener('click', function(){
      var id=this.dataset.id, name=this.dataset.name;
      if(selected[id]) delete selected[id]; else if(!isAll) selected[id]=name;
      render(); search.focus();
    });
  });

  document.getElementById('composeForm').addEventListener('submit', function(e){
    var ok = isAll || Object.keys(selected).length>0;
    errLbl.style.display = ok?'none':'';
    if(!ok) e.preventDefault();
  });

  document.getElementById('composeModal').addEventListener('show.bs.modal', function(e){
    isAll=false; selected={};
    search.value=''; dropdown.style.display='none';
    var btn=e.relatedTarget;
    if(btn && btn.dataset.recipient){
      selected[btn.dataset.recipient] = btn.dataset.recipientName || 'User';
      document.getElementById('subjectInput').value = btn.dataset.subject||'';
    } else {
      document.getElementById('subjectInput').value='';
    }
    render();
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
