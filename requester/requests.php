<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'requester') {
  header('Location: ../main/index.php');
  exit;
}
require_once __DIR__ . '/../supabase_rest.php';
require_once __DIR__ . '/../helpers/work_request_deadlines.php';

$errors = [];
$success = '';

// Resolve display name from DB - prioritize by name field
$displayName = (string)($user['name'] ?? 'Requester');
try {
  $query = ['select' => '*', 'limit' => 1];
  $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
  
  if ($userName !== '') {
    // Try to fetch by name first (with requester role filter)
    $query['name'] = 'eq.' . $userName;
    $query['user_type'] = 'ilike.requester';
  } elseif (isset($user['id']) && $user['id'] !== null && $user['id'] !== '') {
    $query = ['select' => '*', 'limit' => 1];
    $query['id'] = 'eq.' . (string)$user['id'];
  } else {
    $query = null;
  }
  
  if ($query !== null) {
    $rows = supabase_request('GET', 'users', null, $query);
    if (is_array($rows) && count($rows) > 0 && isset($rows[0]['name']) && trim((string)$rows[0]['name']) !== '') {
      $displayName = (string)$rows[0]['name'];
      $_SESSION['user']['name'] = $displayName; // keep session in sync
    }
  }
} catch (Throwable $e) {
  // Fall back to session name
}

// Resolve user_id to fetch requests - prioritize by name
$resolvedUserId = null;
if (isset($user['id']) && $user['id'] !== '' && $user['id'] !== null) {
  $resolvedUserId = (int)$user['id'];
} else {
  // Try to resolve by name first, then email as fallback
  $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
  if ($userName !== '') {
    try {
      $rows = supabase_request('GET', 'users', null, ['select' => 'id', 'name' => 'eq.' . $userName, 'user_type' => 'ilike.requester', 'limit' => 1]);
      if (is_array($rows) && count($rows) > 0 && isset($rows[0]['id'])) {
        $resolvedUserId = (int)$rows[0]['id'];
        $_SESSION['user']['id'] = $resolvedUserId;
      }
    } catch (Throwable $e) {
    }
  }
  // Fallback to email only if name lookup failed
  if ($resolvedUserId === null) {
    $userEmail = isset($user['email']) ? (string)$user['email'] : '';
    if ($userEmail !== '') {
      try {
        $rows = supabase_request('GET', 'users', null, ['select' => 'id', 'email' => 'eq.' . $userEmail, 'limit' => 1]);
        if (is_array($rows) && count($rows) > 0 && isset($rows[0]['id'])) {
          $resolvedUserId = (int)$rows[0]['id'];
          $_SESSION['user']['id'] = $resolvedUserId;
        }
      } catch (Throwable $e) {
      }
    }
  }
}

// Fetch work requests for this user
$requests = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'approve' && $resolvedUserId !== null) {
    $requestId = trim((string)($_POST['id'] ?? ''));
    if ($requestId === '') {
      $errors[] = 'Invalid request id.';
    } else {
      try {
        $existing = supabase_request('GET', 'work_request', null, [
          'select' => 'id,status',
          'id' => 'eq.' . $requestId,
          'user_id' => 'eq.' . $resolvedUserId,
          'limit' => 1
        ]);
        if (!is_array($existing) || count($existing) === 0) {
          $errors[] = 'Request not found.';
        } else {
          $statusLower = strtolower((string)($existing[0]['status'] ?? ''));
          if (!in_array($statusLower, ['for pickup/confirmation', 'waiting for pickup/confirmation', 'waiting for pick up/confirmation'], true)) {
            $errors[] = 'This request is not ready for completion approval.';
          }
        }
      } catch (Throwable $e) {
        $errors[] = 'Unable to verify request.';
      }

      if (!$errors) {
        $file = $_FILES['proof_image'] ?? null;
        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
          $errors[] = 'Please upload an image showing the completed work.';
        } else {
          $err = (int)($file['error'] ?? UPLOAD_ERR_OK);
          $size = (int)($file['size'] ?? 0);
          $tmp = (string)($file['tmp_name'] ?? '');
          $name = (string)($file['name'] ?? '');
          $allowedExt = ['jpg','jpeg','png','gif','webp'];
          $maxBytes = 8 * 1024 * 1024;
          $uploadsDir = realpath(__DIR__ . '/../uploads');
          if ($uploadsDir === false) { $uploadsDir = __DIR__ . '/../uploads'; }
          if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

          if ($err !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed.';
          } elseif ($size <= 0 || $size > $maxBytes || $tmp === '' || !is_uploaded_file($tmp)) {
            $errors[] = 'Invalid file.';
          } else {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) { $ext = 'jpg'; }
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'work-done';
            $unique = $safeName . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
            $destPath = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $unique;
            if (!@move_uploaded_file($tmp, $destPath)) {
              $errors[] = 'Unable to save uploaded file.';
            } else {
              $relative = 'uploads/' . $unique;
              $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
              $update = [
                'image_of_work_done' => $relative,
                'status' => 'Completed',
                'date_finish' => $now->format('Y-m-d'),
                'time_finish' => $now->format('H:i:s'),
              ];
              try {
                supabase_request('PATCH', 'work_request', $update, [
                  'id' => 'eq.' . $requestId,
                  'user_id' => 'eq.' . $resolvedUserId,
                ]);
                $success = 'Thank you! The task has been marked as completed.';
              } catch (Throwable $e) {
                $errors[] = 'Failed to update request status.';
              }
            }
          }
        }
      }
    }
  }
}

if ($resolvedUserId !== null) {
  try {
    $query = [
      'select' => '*',
      'user_id' => 'eq.' . $resolvedUserId,
      'order' => 'date_requested.desc'
    ];
    $requests = supabase_request('GET', 'work_request', null, $query);
    if (!is_array($requests)) {
      $requests = [];
    }
  } catch (Throwable $e) {
    $requests = [];
  }
}

// Helper function to format status badge
function formatStatus(string $status): string {
  $statusLower = strtolower($status);
  $classes = 'status-badge ';
  switch ($statusLower) {
    case 'pending':
      $classes .= 'status-pending';
      break;
    case 'in progress':
    case 'in-progress':
    case 'for pickup/confirmation':
    case 'waiting for pickup/confirmation':
    case 'waiting for pick up/confirmation':
      $classes .= 'status-progress';
      break;
    case 'completed':
    case 'done':
    case 'task completed':
      $classes .= 'status-completed';
      break;
    case 'cancelled':
    case 'canceled':
      $classes .= 'status-cancelled';
      break;
    default:
      $classes .= 'status-default';
  }
  return '<span class="' . htmlspecialchars($classes) . '">' . htmlspecialchars($status) . '</span>';
}

// Helper function to format date
function formatDate(?string $dateStr): string {
  if ($dateStr === null || trim($dateStr) === '') {
    return 'N/A';
  }
  try {
    $dt = new DateTime($dateStr);
    return $dt->format('M d, Y');
  } catch (Exception $e) {
    return htmlspecialchars($dateStr);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Requests</title>
<style>
  :root {
    --maroon-700: #5a0f1b;
    --maroon-600: #7a1b2a;
    --maroon-400: #a42b43;
    --offwhite: #f9f6f7;
    --text: #222;
    --muted: #6b7280;
    --border: #e5e7eb;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #fff; color: var(--text); }
  .topbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #eee; background: var(--offwhite); }
  .brand { font-weight: 700; color: var(--maroon-700); }
  .profile { display: flex; align-items: center; gap: 10px; }
  .avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: #ddd; }
  .name { font-weight: 600; color: var(--maroon-700); }
  .container { max-width: 1100px; margin: 20px auto; padding: 0 16px; }
  .actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 20px; }
  .btn { display: inline-block; text-decoration: none; text-align: center; padding: 14px 16px; border-radius: 10px; border: 1px solid #e5e5e5; background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .15s ease, border-color .15s ease, transform .1s ease; }
  .btn:hover { background: #fff7f8; border-color: var(--maroon-400); }
  .btn:active { transform: translateY(1px); }
  .btn.small { padding: 8px 12px; font-size: 13px; border-radius: 8px; }
  .btn.primary { background: var(--maroon-600); color: #fff; border-color: var(--maroon-600); }
  .btn.primary:hover { background: var(--maroon-700); border-color: var(--maroon-700); }
  .page-title { margin: 0 0 6px; color: var(--maroon-700); font-size: 22px; font-weight: 700; }
  .subtext { margin: 0 0 20px; color: var(--muted); font-size: 14px; }
  .request-list { display: flex; flex-direction: column; gap: 16px; }
  .request-card { border: 1px solid var(--border); border-radius: 12px; padding: 18px; background: #fff; transition: box-shadow .2s ease; }
  .request-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
  .request-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; flex-wrap: wrap; gap: 12px; }
  .request-id { font-weight: 700; color: var(--maroon-700); font-size: 16px; }
  .status-badge { display: inline-block; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
  .status-pending { background: #fef3c7; color: #92400e; }
  .status-progress { background: #dbeafe; color: #1e40af; }
  .status-completed { background: #d1fae5; color: #065f46; }
  .status-cancelled { background: #fee2e2; color: #991b1b; }
  .status-default { background: #f3f4f6; color: #374151; }
  .request-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .detail-item { display: flex; flex-direction: column; gap: 4px; }
  .detail-label { font-size: 12px; color: var(--muted); font-weight: 600; }
  .detail-value { color: var(--text); font-size: 14px; }
  .detail-value.text-col-full { grid-column: 1 / -1; }
  .deadline-indicator { font-weight: 600; color: var(--maroon-600); display: inline-block; }
  .deadline-indicator.overdue { color: #b91c1c; }
  .deadline-indicator.due_soon { color: #92400e; }
  .deadline-indicator.on_track { color: #047857; }
  .empty-state { text-align: center; padding: 40px 20px; color: var(--muted); }
  .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
  .notice { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
  .notice.error { background: #fff1f2; color: #7a1b2a; border: 1px solid #ffd5da; }
  .notice.success { background: #ecfeff; color: #0b6b74; border: 1px solid #cffafe; }
  .approve-form { margin-top: 12px; padding: 12px; border: 1px dashed var(--border); border-radius: 10px; background: #fafafa; }
  .approve-form label { font-weight: 600; color: var(--maroon-700); display: block; margin-bottom: 6px; }
  .approve-form input[type="file"] { width: 100%; border: 1px solid var(--border); border-radius: 8px; padding: 10px; }
  .approve-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
  @media (max-width: 640px) {
    .actions { grid-template-columns: 1fr; }
    .request-details { grid-template-columns: 1fr; }
    .request-header { flex-direction: column; }
  }
</style>
</head>
<body>
  <header class="topbar">
    <div class="brand">RCC Requester</div>
    <div class="profile">
      <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main class="container">
    <section class="actions" aria-label="Navigation actions">
      <a class="btn" href="dashboard.php">Back to Home</a>
      <a class="btn" href="requestForm.php">Submit New Request</a>
    </section>

    <h2 class="page-title">My Requests</h2>
    <p class="subtext">View all your submitted work requests and their current status.</p>

    <?php if ($errors): ?>
      <div class="notice error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php elseif ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (count($requests) === 0): ?>
      <div class="empty-state">
        <div class="empty-state-icon">📋</div>
        <p style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">No requests found</p>
        <p>You haven't submitted any work requests yet.</p>
        <div style="margin-top: 20px;">
          <a class="btn" href="requestForm.php">Submit Your First Request</a>
        </div>
      </div>
    <?php else: ?>
      <div class="request-list">
        <?php foreach ($requests as $request): ?>
          <?php $deadlineMeta = wr_enrich_deadline($request); ?>
          <?php $statusLower = strtolower((string)($request['status'] ?? '')); ?>
          <div class="request-card">
            <div class="request-header">
              <div class="request-id">Request #<?php echo htmlspecialchars((string)($request['id'] ?? 'N/A')); ?></div>
              <?php echo formatStatus((string)($request['status'] ?? 'Pending')); ?>
            </div>
            <div class="request-details">
              <div class="detail-item">
                <span class="detail-label">Date Requested</span>
                <span class="detail-value"><?php echo formatDate($request['date_requested'] ?? null); ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Type of Request</span>
                <span class="detail-value"><?php echo htmlspecialchars((string)($request['type_of_request'] ?? 'N/A')); ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Department</span>
                <span class="detail-value"><?php echo htmlspecialchars((string)($request['department'] ?? 'N/A')); ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Location</span>
                <span class="detail-value"><?php echo htmlspecialchars((string)($request['location'] ?? 'N/A')); ?></span>
              </div>
              <?php if (!empty($deadlineMeta['deadline_display'])): ?>
                <div class="detail-item">
                  <span class="detail-label">Target Deadline</span>
                  <span class="detail-value deadline-indicator <?php echo htmlspecialchars((string)$deadlineMeta['deadline_state']); ?>">
                    <?php echo htmlspecialchars((string)$deadlineMeta['deadline_display']); ?>
                    <small style="display:block; color:#6b7280; font-weight:400;"><?php echo htmlspecialchars((string)$deadlineMeta['human_delta']); ?></small>
                  </span>
                </div>
              <?php endif; ?>
              <?php if (!empty($request['description_of_work'])): ?>
                <div class="detail-item text-col-full">
                  <span class="detail-label">Description</span>
                  <span class="detail-value"><?php echo nl2br(htmlspecialchars((string)$request['description_of_work'])); ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($request['time_duration'])): ?>
                <div class="detail-item">
                  <span class="detail-label">Time Duration</span>
                  <span class="detail-value"><?php echo htmlspecialchars((string)$request['time_duration']); ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($request['no_of_personnel_needed'])): ?>
                <div class="detail-item">
                  <span class="detail-label">Personnel Needed</span>
                  <span class="detail-value"><?php echo htmlspecialchars((string)$request['no_of_personnel_needed']); ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($request['staff_assigned'])): ?>
                <div class="detail-item">
                  <span class="detail-label">Staff Assigned</span>
                  <span class="detail-value"><?php echo htmlspecialchars((string)$request['staff_assigned']); ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($request['image_of_work'])): ?>
                <div class="detail-item text-col-full">
                  <span class="detail-label">Image of Work</span>
                  <div style="margin-top: 8px;">
                    <img src="../<?php echo htmlspecialchars((string)$request['image_of_work']); ?>" alt="Work image" style="max-width: 200px; max-height: 150px; border-radius: 8px; border: 1px solid var(--border);">
                  </div>
                </div>
              <?php endif; ?>
              <?php if (!empty($request['image_of_work_done'])): ?>
                <div class="detail-item text-col-full">
                  <span class="detail-label">Proof of Completion</span>
                  <div style="margin-top: 8px;">
                    <img src="../<?php echo htmlspecialchars((string)$request['image_of_work_done']); ?>" alt="Completed work image" style="max-width: 220px; max-height: 160px; border-radius: 8px; border: 1px solid var(--border);">
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <?php if (in_array($statusLower, ['for pickup/confirmation', 'waiting for pickup/confirmation', 'waiting for pick up/confirmation'], true)): ?>
              <div class="completion-actions" style="margin-top:14px;">
                <p style="margin:0 0 10px; color: var(--muted);">Review the completed work and approve to close this request.</p>
                <button class="btn small" type="button" data-approve-toggle="<?php echo htmlspecialchars((string)$request['id']); ?>">Approve Task Completion</button>
                <div id="approve-form-<?php echo htmlspecialchars((string)$request['id']); ?>" class="approve-form" style="display:none;">
                  <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$request['id']); ?>">
                    <label for="proof-<?php echo htmlspecialchars((string)$request['id']); ?>">Upload proof of completed work</label>
                    <input id="proof-<?php echo htmlspecialchars((string)$request['id']); ?>" name="proof_image" type="file" accept="image/*" required>
                    <small style="display:block; margin-top:6px; color:#6b7280;">Accepted formats: JPG, PNG, GIF, WEBP (max 8MB).</small>
                    <div class="approve-actions">
                      <button class="btn primary" type="submit">Submit</button>
                      <button class="btn small" type="button" data-approve-cancel="<?php echo htmlspecialchars((string)$request['id']); ?>">Cancel</button>
                    </div>
                  </form>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
  <script>
  (function(){
    function toggleForm(id, show) {
      var el = document.getElementById('approve-form-' + id);
      if (!el) return;
      if (typeof show === 'boolean') {
        el.style.display = show ? 'block' : 'none';
      } else {
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
      }
    }
    document.querySelectorAll('[data-approve-toggle]').forEach(function(btn){
      btn.addEventListener('click', function(){
        toggleForm(this.getAttribute('data-approve-toggle'), true);
      });
    });
    document.querySelectorAll('[data-approve-cancel]').forEach(function(btn){
      btn.addEventListener('click', function(){
        toggleForm(this.getAttribute('data-approve-cancel'), false);
      });
    });
  })();
</script>
</body>
</html>
