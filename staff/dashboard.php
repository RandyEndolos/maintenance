<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'staff') {
  header('Location: ../main/index.php');
  exit;
}
require_once __DIR__ . '/../supabase_rest.php';
require_once __DIR__ . '/../helpers/staff_status.php';
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

$manualStatuses = load_staff_manual_statuses();
$currentManual = staff_manual_status($staffName, $manualStatuses);
$statusMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
  $newStatus = (string)($_POST['manual_status'] ?? 'available');
  if (!in_array($newStatus, ['available', 'on_leave', 'absence'], true)) {
    $newStatus = 'available';
  }
  update_staff_manual_status($staffName, $newStatus);
  $manualStatuses = load_staff_manual_statuses();
  $currentManual = staff_manual_status($staffName, $manualStatuses);
  $statusMessage = 'Availability updated.';
}

$busyMap = [];
try {
  $activeRows = supabase_request('GET', 'work_request', null, [
    'select' => 'id,staff_assigned,status'
  ]);
  if (!is_array($activeRows)) { $activeRows = []; }
  $busyMap = build_busy_staff_map($activeRows);
} catch (Throwable $e) {
  $busyMap = [];
}

$displayStatus = derive_staff_display_status($staffName, $manualStatuses, $busyMap);

$deadlineAlerts = [];
if ($staffName !== '') {
  try {
    $assignedRows = supabase_request('GET', 'work_request', null, [
      'select' => 'id,type_of_request,department,requesters_name,status,date_requested,date_start,time_start,time_duration,staff_assigned',
      'staff_assigned' => 'ilike.*' . $staffName . '*'
    ]);
    if (!is_array($assignedRows)) { $assignedRows = []; }
    $now = new DateTimeImmutable('now', wr_deadline_timezone());
    foreach ($assignedRows as $row) {
      if (!wr_is_active_status($row['status'] ?? null)) {
        continue;
      }
      $meta = wr_enrich_deadline($row, $now);
      if (in_array($meta['deadline_state'], ['overdue', 'due_soon'], true)) {
        $row['deadline_meta'] = $meta;
        $deadlineAlerts[] = $row;
      }
    }
    usort($deadlineAlerts, function($a, $b) {
      $aSec = $a['deadline_meta']['seconds_remaining'] ?? 0;
      $bSec = $b['deadline_meta']['seconds_remaining'] ?? 0;
      return $aSec <=> $bSec;
    });
  } catch (Throwable $e) {
    $deadlineAlerts = [];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard</title>
<style>
  :root {
    --maroon-700: #5a0f1b;
    --maroon-600: #7a1b2a;
    --maroon-400: #a42b43;
    --offwhite: #f9f6f7;
    --text: #222;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #fff; color: var(--text); }
  .topbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #eee; background: var(--offwhite); }
  .brand { font-weight: 700; color: var(--maroon-700); }
  .profile { display: flex; align-items: center; gap: 10px; }
  .avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: #ddd; }
  .name { font-weight: 600; color: var(--maroon-700); }
  .container { max-width: 1100px; margin: 20px auto; padding: 0 16px; }
  .actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .btn { padding: 14px 16px; border-radius: 10px; border: 1px solid #e5e5e5; background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .15s ease, border-color .15s ease, transform .1s ease; }
  .btn:hover { background: #fff7f8; border-color: var(--maroon-400); }
  .btn:active { transform: translateY(1px); }
  @media (max-width: 640px) { .actions { grid-template-columns: 1fr; } }
  .status-card { margin-top: 20px; border: 1px solid #eee; border-radius: 12px; padding: 16px; background: #fff; }
  .status-pill { display: inline-block; padding: 6px 12px; border-radius: 999px; font-weight: 600; font-size: 14px; }
  .status-pill.available { background: #d1fae5; color: #065f46; }
  .status-pill.assigned { background: #dbeafe; color: #1d4ed8; }
  .status-pill.leave { background: #fde68a; color: #92400e; }
  .status-pill.absence { background: #fee2e2; color: #b91c1c; }
  .status-form { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .status-form select { padding: 10px 12px; border-radius: 8px; border: 1px solid #e5e5e5; font: inherit; }
  .status-note { margin-top: 8px; font-size: 13px; color: #6b7280; }
  .status-card { border: 1px solid #eee; border-radius: 10px; padding: 12px; background: #fff; }
  .status-form { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .status-form select { padding: 8px 10px; border-radius: 8px; border: 1px solid #e5e5e5; font: inherit; font-size: 13px; }
  .deadline-card { border: 1px solid #eee; border-radius: 10px; padding: 12px; background: #fff; }
  .deadline-card h2 { margin: 0 0 6px; font-size: 16px; color: var(--maroon-700); }
  .deadline-list { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
  .deadline-item { border: 1px solid #f3f4f6; border-radius: 8px; padding: 10px; background: #fff; }
  .deadline-item.overdue { border-color: #fecaca; background: #fff5f5; }
  .deadline-item.due_soon { border-color: #fde68a; background: #fffbeb; }
  .deadline-item h3 { margin: 0 0 4px; font-size: 14px; color: var(--maroon-700); }
  .deadline-meta { font-size: 12px; color: #555; display: flex; flex-wrap: wrap; gap: 8px; }
  .deadline-badge { padding: 3px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
  .deadline-badge.overdue { background: #fee2e2; color: #b91c1c; }
  .deadline-badge.due_soon { background: #fef3c7; color: #92400e; }
  .deadline-empty { color: #6b7280; font-size: 13px; }
  .dashboard-grid { display:grid; grid-template-columns: minmax(0, 2.5fr) minmax(280px, 0.8fr); gap:16px; align-items:start; margin-top:20px; }
  .dashboard-calendar { min-width:0; }
  .dashboard-side { display:flex; flex-direction:column; gap:12px; min-width:0; }
  .dashboard-side > section { width:100%; }
  @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: minmax(0, 1.8fr) minmax(260px, 1fr); } }
  @media (max-width: 900px) { .dashboard-grid { grid-template-columns: 1fr; } }
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
      <a class="btn" href="/ERS/staff/information.php">Information</a>
      <a class="btn" href="/ERS/staff/pendingtask.php">Pending Task</a>
      <a class="btn" href="/ERS/staff/accomplishment.php">Accomplishments</a>
      <button class="btn" type="button" onclick="document.getElementById('status-card').scrollIntoView({behavior:'smooth'});">Submit Work Leave/Absence</button>
    </section>
    <div class="dashboard-grid">
      <div class="dashboard-calendar">
        <?php require_once __DIR__ . '/../components/calendar.php'; ?>
      </div>
      <div class="dashboard-side">
        <section id="status-card" class="status-card" aria-label="Current availability">
          <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div>
              <div style="font-weight:600; color:var(--maroon-700);">Current Status</div>
              <div class="status-pill <?php echo strtolower(str_replace(' ', '', $displayStatus)) === 'assignedwork' ? 'assigned' : (strtolower($displayStatus)==='on leave'?'leave':(strtolower($displayStatus)==='absence'?'absence':'available')); ?>">
                <?php echo htmlspecialchars($displayStatus); ?>
              </div>
            </div>
            <?php if ($statusMessage !== ''): ?>
              <div style="color:#059669; font-weight:600;"><?php echo htmlspecialchars($statusMessage); ?></div>
            <?php endif; ?>
          </div>
          <form method="post" class="status-form">
            <input type="hidden" name="update_status" value="1">
            <label for="manual_status" style="font-weight:600; color:var(--maroon-700);">Update availability</label>
            <select id="manual_status" name="manual_status">
              <option value="available" <?php echo $currentManual==='available'?'selected':''; ?>>Available for Work</option>
              <option value="on_leave" <?php echo $currentManual==='on_leave'?'selected':''; ?>>On Leave</option>
              <option value="absence" <?php echo $currentManual==='absence'?'selected':''; ?>>Absence</option>
            </select>
            <button class="btn" type="submit">Save</button>
          </form>
          <p class="status-note">Setting On Leave or Absence hides you from future task assignments until you switch back to Available.</p>
        </section>
        <section class="deadline-card" aria-label="Upcoming task deadlines">
          <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
            <h2>My Deadline Alerts</h2>
            <div class="deadline-badge" style="background:#f3f4f6; color:#374151;">Upcoming tasks</div>
          </div>
          <?php if (!$deadlineAlerts): ?>
            <div class="deadline-empty">No assigned tasks are due soon.</div>
          <?php else: ?>
            <div class="deadline-list">
              <?php foreach (array_slice($deadlineAlerts, 0, 4) as $alert): ?>
                <?php $meta = $alert['deadline_meta']; ?>
                <div class="deadline-item <?php echo htmlspecialchars((string)$meta['deadline_state']); ?>">
                  <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
                    <h3>#<?php echo htmlspecialchars((string)$alert['id']); ?> • <?php echo htmlspecialchars((string)($alert['type_of_request'] ?? 'Work Request')); ?></h3>
                    <span class="deadline-badge <?php echo htmlspecialchars((string)$meta['deadline_state']); ?>"><?php echo $meta['deadline_state']==='overdue' ? 'Overdue' : 'Due Soon'; ?></span>
                  </div>
                  <div class="deadline-meta">
                    <span><strong>Requester:</strong> <?php echo htmlspecialchars((string)($alert['requesters_name'] ?? 'N/A')); ?></span>
                    <span><strong>Department:</strong> <?php echo htmlspecialchars((string)($alert['department'] ?? 'N/A')); ?></span>
                  </div>
                  <div class="deadline-meta">
                    <span><strong>Deadline:</strong> <?php echo htmlspecialchars((string)($meta['deadline_display'] ?? 'N/A')); ?></span>
                    <span><strong>Time Remaining:</strong> <?php echo htmlspecialchars((string)($meta['human_delta'] ?? 'N/A')); ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>
</body>
</html>

