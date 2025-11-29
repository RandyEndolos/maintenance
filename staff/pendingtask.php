<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'staff') {
  header('Location: ../main/index.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';
require_once __DIR__ . '/../helpers/work_request_deadlines.php';

// Fetch staff name from database - prioritize by name field
$staffName = (string)($user['name'] ?? '');
$displayName = $staffName;
try {
  $query = ['select' => 'name', 'limit' => 1];
  $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
  
  if ($userName !== '') {
    // Try to fetch by name first (with staff role filter)
    $query['name'] = 'eq.' . $userName;
    $query['user_type'] = 'ilike.staff';
  } elseif (isset($user['id']) && $user['id'] !== null && $user['id'] !== '') {
    $query = ['select' => 'name', 'limit' => 1];
    $query['id'] = 'eq.' . (string)$user['id'];
  } else {
    $query = null;
  }
  
  if ($query !== null) {
    $rows = supabase_request('GET', 'users', null, $query);
    if (is_array($rows) && count($rows) > 0) {
      if (isset($rows[0]['name']) && trim((string)$rows[0]['name']) !== '') {
        $displayName = (string)$rows[0]['name'];
        $staffName = $displayName;
        $_SESSION['user']['name'] = $displayName; // Keep session in sync
      }
    }
  }
} catch (Throwable $e) {
  // Fall back to session name
}

$errors = [];
$success = '';

// Handle Accept action -> move task to In Progress or mark waiting for pickup/confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $id = trim((string)($_POST['id'] ?? ''));
  if ($id === '') {
    $errors[] = 'Invalid request id.';
  } elseif ($action === 'accept') {
    try {
      $update = [
        'status' => 'In Progress',
      ];
      // Optionally set start time/date if not already set
      $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
      $update['date_start'] = $now->format('Y-m-d');
      $update['time_start'] = $now->format('H:i:s');
      $where = ['id' => 'eq.' . $id];
      if ($staffName !== '') {
        $where['staff_assigned'] = 'ilike.*' . $staffName . '*';
      }
      supabase_request('PATCH', 'work_request', $update, $where);
      $success = 'Task accepted and marked as In Progress.';
    } catch (Throwable $e) {
      $errors[] = 'Failed to accept task.';
    }
  } elseif ($action === 'mark_pickup') {
    try {
      $where = ['id' => 'eq.' . $id];
      if ($staffName !== '') {
        $where['staff_assigned'] = 'ilike.*' . $staffName . '*';
      }
      supabase_request('PATCH', 'work_request', [
        'status' => 'For Pickup/Confirmation',
      ], $where);
      $success = 'Task marked as waiting for pick up/confirmation.';
    } catch (Throwable $e) {
      $errors[] = 'Failed to update task status.';
    }
  }
}

// Fetch tasks assigned to this staff that are awaiting acceptance
$pendingRequests = [];
try {
  $filters = [
    'select' => '*',
    'order' => 'date_requested.desc',
  ];
  if ($staffName !== '') {
    $filters['staff_assigned'] = 'ilike.*' . $staffName . '*';
  }
  $filters['status'] = 'in.(Pending,Waiting for Staff)';
  $pendingRequests = supabase_request('GET', 'work_request', null, $filters);
  if (!is_array($pendingRequests)) { $pendingRequests = []; }
} catch (Throwable $e) {
  $pendingRequests = [];
}

// Fetch tasks already assigned to this staff to show ongoing / pickup lists
$ongoingRequests = [];
$handoffRequests = [];
try {
  $assignedFilters = [
    'select' => '*',
    'order' => 'date_requested.desc',
  ];
  if ($staffName !== '') {
    $assignedFilters['staff_assigned'] = 'ilike.*' . $staffName . '*';
  }
  $assigned = supabase_request('GET', 'work_request', null, $assignedFilters);
  if (!is_array($assigned)) { $assigned = []; }
  foreach ($assigned as $req) {
    $statusLower = strtolower((string)($req['status'] ?? ''));
    if (in_array($statusLower, ['in progress', 'in-progress', 'ongoing'], true)) {
      $ongoingRequests[] = $req;
    } elseif (in_array($statusLower, ['for pickup/confirmation', 'waiting for pickup/confirmation', 'waiting for pick up/confirmation'], true)) {
      $handoffRequests[] = $req;
    }
  }
} catch (Throwable $e) {
  $ongoingRequests = [];
  $handoffRequests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pending Task</title>
<style>
  :root{
    --maroon-900: #3f0710;
    --maroon-800: #5a0f1b;
    --maroon-700: #7a1b2a;
    --maroon-600: #8b1f2f;
    --maroon-500: #a42b43;
    --muted: #fbf6f6;
    --card: #ffffff;
    --text: #111827;
    --muted-text: #6b7280;
    --success: #0b6b74;
    --danger: #b91c1c;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:Inter, -apple-system, system-ui,'Segoe UI',Roboto,Arial;background:linear-gradient(180deg,var(--muted),#fff 60%);color:var(--text);-webkit-font-smoothing:antialiased}
  .topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:linear-gradient(90deg,var(--maroon-800),var(--maroon-700));color:#fff;position:sticky;top:0;z-index:40;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
  .brand{font-weight:800}
  .profile{display:flex;align-items:center;gap:12px}
  .name{font-weight:700;color:#fff}
  .container{max-width:1100px;margin:28px auto;padding:0 18px}
  .actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}
  .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:10px;border:0;background:var(--maroon-700);color:#fff;font-weight:700;cursor:pointer;transition:transform .12s ease,background .12s ease}
  .btn.secondary{background:transparent;border:1px solid rgba(15,23,42,0.06);color:var(--maroon-700);font-weight:600}
  .btn.small{padding:8px 10px;font-weight:600}
  @media(max-width:820px){.actions{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:480px){.actions{grid-template-columns:1fr}}

  .card{background:var(--card);border-radius:12px;padding:16px;border:1px solid rgba(15,23,42,0.04);box-shadow:0 8px 20px rgba(16,24,40,0.04)}
  .list{display:grid;gap:12px}
  .item{padding:14px;border-radius:12px;border:1px solid rgba(15,23,42,0.04);background:#fff}
  .row{display:flex;gap:12px;align-items:center;justify-content:space-between}
  .title{font-weight:700;color:var(--maroon-900)}
  .meta{color:var(--muted-text);font-size:14px}
  .notice{padding:10px 12px;border-radius:8px}
  .error{background:#fff1f2;color:var(--danger);border:1px solid #ffd5da}
  .success{background:#ecfeff;color:var(--success);border:1px solid #cffafe}
  .empty{color:var(--muted-text);font-style:italic}
  form.inline{display:inline;margin:0}
  .pill{display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(15,23,42,0.04);font-size:12px;color:var(--muted-text);font-weight:700}
  .deadline-label{font-weight:700}
  .deadline-label.overdue{color:var(--danger)}
  .deadline-label.due_soon{color:var(--maroon-600)}
  .deadline-label.on_track{color:var(--success)}
  @media(max-width:480px){.row{flex-direction:column;align-items:flex-start;gap:8px}}
</style>
</head>
<body>
  <header class="topbar">
    <div class="brand">RCC Staff</div>
    <div class="profile">
      <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main class="container">
    <section class="actions" aria-label="Staff actions">
      <a class="btn" href="/maintenance/staff/dashboard.php">Back to Home</a>
      <a class="btn" href="/maintenance/staff/information.php">Information</a>
    </section>

    <?php if ($errors): ?>
      <div class="notice error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php elseif ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <section class="card" aria-label="Pending tasks">
      <div class="row" style="margin-bottom:8px;">
        <div class="title">Pending Task</div>
        <span class="pill"><?php echo count($pendingRequests); ?> item(s)</span>
      </div>
      <?php if (!$pendingRequests): ?>
        <div class="empty">No tasks awaiting your acceptance.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($pendingRequests as $req): ?>
            <?php $deadlineMeta = wr_enrich_deadline($req); ?>
            <div class="item">
              <div class="row"><div class="title">#<?php echo htmlspecialchars((string)$req['id']); ?> • <?php echo htmlspecialchars((string)($req['type_of_request'] ?? 'Work Request')); ?></div><div class="meta"><?php echo htmlspecialchars((string)($req['department'] ?? '')); ?></div></div>
              <div class="meta">Requested by: <?php echo htmlspecialchars((string)($req['requesters_name'] ?? '')); ?> • Location: <?php echo htmlspecialchars((string)($req['location'] ?? '')); ?></div>
              <div class="meta">Description: <?php echo htmlspecialchars((string)($req['description_of_work'] ?? '')); ?></div>
              <?php if (!empty($deadlineMeta['deadline_display'])): ?>
                <div class="meta deadline-label <?php echo htmlspecialchars((string)$deadlineMeta['deadline_state']); ?>">
                  Deadline: <?php echo htmlspecialchars((string)$deadlineMeta['deadline_display']); ?> (<?php echo htmlspecialchars((string)$deadlineMeta['human_delta']); ?>)
                </div>
              <?php endif; ?>
              <div class="row">
                <div class="meta">Status: <?php echo htmlspecialchars((string)($req['status'] ?? '')); ?></div>
                <form class="inline" method="post">
                  <input type="hidden" name="action" value="accept">
                  <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$req['id']); ?>">
                  <button class="btn small" type="submit">Accept</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card" aria-label="Ongoing tasks" style="margin-top:16px;">
      <div class="row" style="margin-bottom:8px;">
        <div class="title">Ongoing Tasks</div>
        <span class="pill"><?php echo count($ongoingRequests); ?> item(s)</span>
      </div>
      <?php if (!$ongoingRequests): ?>
        <div class="empty">No tasks are currently in progress.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($ongoingRequests as $req): ?>
            <?php $deadlineMeta = wr_enrich_deadline($req); ?>
            <div class="item">
              <div class="row"><div class="title">#<?php echo htmlspecialchars((string)$req['id']); ?> • <?php echo htmlspecialchars((string)($req['type_of_request'] ?? 'Work Request')); ?></div><div class="meta"><?php echo htmlspecialchars((string)($req['department'] ?? '')); ?></div></div>
              <div class="meta">Requested by: <?php echo htmlspecialchars((string)($req['requesters_name'] ?? '')); ?></div>
              <div class="meta">Started: <?php echo htmlspecialchars((string)($req['date_start'] ?? 'N/A')); ?> <?php echo htmlspecialchars((string)($req['time_start'] ?? '')); ?></div>
              <?php if (!empty($deadlineMeta['deadline_display'])): ?>
                <div class="meta deadline-label <?php echo htmlspecialchars((string)$deadlineMeta['deadline_state']); ?>">
                  Deadline: <?php echo htmlspecialchars((string)$deadlineMeta['deadline_display']); ?> (<?php echo htmlspecialchars((string)$deadlineMeta['human_delta']); ?>)
                </div>
              <?php endif; ?>
              <div class="row">
                <div class="meta">Status: <?php echo htmlspecialchars((string)($req['status'] ?? 'In Progress')); ?></div>
                <form class="inline" method="post">
                  <input type="hidden" name="action" value="mark_pickup">
                  <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$req['id']); ?>">
                  <button class="btn small" type="submit">Waiting for Pick Up/Confirmation</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card" aria-label="Awaiting pickup confirmation" style="margin-top:16px;">
      <div class="row" style="margin-bottom:8px;">
        <div class="title">For Pick Up / Confirmation</div>
        <span class="pill"><?php echo count($handoffRequests); ?> item(s)</span>
      </div>
      <?php if (!$handoffRequests): ?>
        <div class="empty">No tasks are waiting for requester confirmation.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($handoffRequests as $req): ?>
            <?php $deadlineMeta = wr_enrich_deadline($req); ?>
            <div class="item">
              <div class="row"><div class="title">#<?php echo htmlspecialchars((string)$req['id']); ?> • <?php echo htmlspecialchars((string)($req['type_of_request'] ?? 'Work Request')); ?></div><div class="meta"><?php echo htmlspecialchars((string)($req['department'] ?? '')); ?></div></div>
              <div class="meta">Awaiting confirmation from: <?php echo htmlspecialchars((string)($req['requesters_name'] ?? '')); ?></div>
              <div class="meta">Status: <?php echo htmlspecialchars((string)($req['status'] ?? 'For Pickup/Confirmation')); ?></div>
              <?php if (!empty($deadlineMeta['deadline_display'])): ?>
                <div class="meta deadline-label <?php echo htmlspecialchars((string)$deadlineMeta['deadline_state']); ?>">
                  Deadline: <?php echo htmlspecialchars((string)$deadlineMeta['deadline_display']); ?> (<?php echo htmlspecialchars((string)$deadlineMeta['human_delta']); ?>)
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>


