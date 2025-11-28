<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
  header('Location: ../main/index.php');
  exit;
}
require_once __DIR__ . '/../supabase_rest.php';
require_once __DIR__ . '/../helpers/staff_status.php';
require_once __DIR__ . '/../helpers/work_request_deadlines.php';

// Fetch fullname from database - prioritize by name field
$displayName = (string)($user['name'] ?? 'Admin');
try {
  $query = ['select' => 'name', 'limit' => 1];
  $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
  
  if ($userName !== '') {
    // Try to fetch by name first (with admin role filter)
    $query['name'] = 'eq.' . $userName;
    $query['user_type'] = 'ilike.admin';
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
        $_SESSION['user']['name'] = $displayName; // Keep session in sync
      }
    }
  }
} catch (Throwable $e) {
  // Fall back to session name
}

// Helper to render status badge styles in PHP templates
function wr_status_badge(string $status): string {
  $statusLower = strtolower($status);
  $class = 'status-badge ';
  if (in_array($statusLower, ['pending'], true)) $class .= 'status-pending';
  elseif (in_array($statusLower, ['waiting for staff', 'in progress', 'in-progress', 'for pickup/confirmation', 'waiting for pickup/confirmation', 'waiting for pick up/confirmation'], true)) $class .= 'status-progress';
  elseif (in_array($statusLower, ['completed', 'done', 'task completed'], true)) $class .= 'status-completed';
  elseif (in_array($statusLower, ['cancelled', 'canceled'], true)) $class .= 'status-cancelled';
  else $class .= 'status-default';
  return '<span class="' . htmlspecialchars($class) . '">' . htmlspecialchars($status ?: 'Unknown') . '</span>';
}

// Fetch summary of work request statuses
$statusSummary = [
  'pending' => 0,
  'waiting for staff' => 0,
  'in progress' => 0,
  'completed' => 0,
];
$recentRequests = [];
try {
  $summaryRows = supabase_request('GET', 'work_request', null, [
    'select' => 'status,count=status',
    'group' => 'status'
  ]);
  if (is_array($summaryRows)) {
    foreach ($summaryRows as $row) {
      $label = strtolower((string)($row['status'] ?? ''));
      $count = (int)($row['count'] ?? 0);
      if ($label === 'waiting for staff') {
        $statusSummary['waiting for staff'] += $count;
      } elseif ($label === 'in progress' || $label === 'in-progress' || $label === 'for pickup/confirmation' || $label === 'waiting for pickup/confirmation' || $label === 'waiting for pick up/confirmation') {
        $statusSummary['in progress'] += $count;
      } elseif ($label === 'pending') {
        $statusSummary['pending'] += $count;
      } elseif ($label === 'completed' || $label === 'task completed') {
        $statusSummary['completed'] += $count;
      } else {
        // track other statuses under pending bucket for visibility
        $statusSummary['pending'] += $count;
      }
    }
  }
} catch (Throwable $e) {
  // leave defaults
}
try {
  $recentRequests = supabase_request('GET', 'work_request', null, [
    'select' => 'id,requesters_name,department,type_of_request,status,date_requested',
    'order' => 'date_requested.desc',
    'limit' => 5
  ]);
  if (!is_array($recentRequests)) {
    $recentRequests = [];
  }
} catch (Throwable $e) {
  $recentRequests = [];
}

$manualStaffStatuses = load_staff_manual_statuses();
$allAssignments = [];
try {
  $allAssignments = supabase_request('GET', 'work_request', null, [
    'select' => 'id,staff_assigned,status'
  ]);
  if (!is_array($allAssignments)) { $allAssignments = []; }
} catch (Throwable $e) {
  $allAssignments = [];
}
$busyStaffMap = build_busy_staff_map($allAssignments);

$staffList = [];
try {
  $staffList = supabase_request('GET', 'users', null, [
    'select' => 'id,name,area_of_work',
    'user_type' => 'ilike.staff',
    'order' => 'name.asc'
  ]);
  if (!is_array($staffList)) { $staffList = []; }
} catch (Throwable $e) {
  $staffList = [];
}

$deadlineAlerts = ['overdue' => [], 'due_soon' => []];
$deadlineCounts = ['overdue' => 0, 'due_soon' => 0];
try {
  $deadlineRows = supabase_request('GET', 'work_request', null, [
    'select' => 'id,requesters_name,department,status,staff_assigned,date_requested,date_start,time_start,time_duration',
    'order' => 'date_requested.asc'
  ]);
  if (!is_array($deadlineRows)) { $deadlineRows = []; }
  $now = new DateTimeImmutable('now', wr_deadline_timezone());
  foreach ($deadlineRows as $row) {
    if (!wr_is_active_status($row['status'] ?? null)) {
      continue;
    }
    $meta = wr_enrich_deadline($row, $now);
    if (in_array($meta['deadline_state'], ['overdue', 'due_soon'], true)) {
      $row['deadline_meta'] = $meta;
      $deadlineAlerts[$meta['deadline_state']][] = $row;
    }
  }
  $deadlineCounts['overdue'] = count($deadlineAlerts['overdue']);
  $deadlineCounts['due_soon'] = count($deadlineAlerts['due_soon']);
} catch (Throwable $e) {
  $deadlineAlerts = ['overdue' => [], 'due_soon' => []];
  $deadlineCounts = ['overdue' => 0, 'due_soon' => 0];
}

function fmt_date_short(?string $dateStr): string {
  if ($dateStr === null || trim($dateStr) === '') return 'N/A';
  try {
    $dt = new DateTime($dateStr);
    return $dt->format('M d, Y');
  } catch (Throwable $e) {
    return htmlspecialchars($dateStr);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard</title>
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
  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
  .stat-card { border: 1px solid #eee; border-radius: 8px; padding: 10px 12px; background:#fff; }
  .stat-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
  .stat-value { font-size: 20px; font-weight: 700; color: var(--maroon-700); margin-top: 4px; }
  @media (max-width: 900px) { .stats { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 500px) { .stats { grid-template-columns: 1fr; } }
  .recent { margin-top: 24px; border: 1px solid #eee; border-radius: 12px; padding: 16px; background:#fff; }
  .recent h2 { margin: 0 0 12px; font-size: 18px; color: var(--maroon-700); }
  .request-list { display: flex; flex-direction: column; gap: 12px; }
  .request-card { border: 1px solid #f0f0f0; border-radius: 10px; padding: 12px; }
  .request-header { display:flex; justify-content: space-between; align-items:center; flex-wrap:wrap; gap:8px; }
  .request-id { font-weight: 600; color: var(--maroon-600); }
  .request-meta { display:flex; gap:16px; font-size: 13px; color:#555; flex-wrap:wrap; margin-top:8px; }
  .status-badge { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
  .status-pending { background:#fef3c7; color:#92400e; }
  .status-progress { background:#dbeafe; color:#1e40af; }
  .status-completed { background:#d1fae5; color:#065f46; }
  .status-cancelled { background:#fee2e2; color:#991b1b; }
  .status-default { background:#f3f4f6; color:#374151; }
  .staff-panel { margin-top: 24px; border: 1px solid #eee; border-radius: 12px; padding: 16px; background:#fff; }
  .staff-panel h2 { margin: 0 0 12px; font-size: 18px; color: var(--maroon-700); }
  .staff-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
  .staff-card { border: 1px solid #f0f0f0; border-radius: 10px; padding: 12px; background:#fafafa; }
  .staff-name { font-weight: 600; color: var(--maroon-700); }
  .staff-area { font-size: 13px; color:#6b7280; margin-top:4px; }
  .staff-status { margin-top: 8px; font-weight:600; }
  .staff-status.assigned { color:#1d4ed8; }
  .staff-status.available { color:#047857; }
  .staff-status.leave { color:#b45309; }
  .staff-status.absence { color:#b91c1c; }
  @media (max-width: 900px) { .staff-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
  @media (max-width: 500px) { .staff-grid { grid-template-columns: 1fr; } }
  .deadline-panel { border:1px solid #eee; border-radius:10px; padding:12px; background:#fff; }
  .deadline-header { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:8px; }
  .deadline-header h2 { margin:0; font-size:16px; color:var(--maroon-700); }
  .deadline-chip { padding:3px 8px; border-radius:999px; font-size:11px; font-weight:600; }
  .deadline-chip.overdue { background:#fee2e2; color:#991b1b; }
  .deadline-chip.due { background:#fef3c7; color:#92400e; }
  .deadline-list { display:flex; flex-direction:column; gap:8px; margin-top:8px; }
  .deadline-item { border:1px solid #f0f0f0; border-radius:8px; padding:10px; background:#fff; }
  .deadline-item.overdue { border-color:#fecaca; background:#fff5f5; }
  .deadline-item.due_soon { border-color:#fde68a; background:#fffbeb; }
  .deadline-meta { font-size:12px; color:#555; display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
  .deadline-badge { padding:3px 8px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; }
  .deadline-badge.overdue { background:#fee2e2; color:#b91c1c; }
  .deadline-badge.due_soon { background:#fef3c7; color:#92400e; }
  .deadline-empty { color:#6b7280; font-size:13px; margin-top:8px; }
  .staff-panel { border:1px solid #eee; border-radius:10px; padding:12px; background:#fff; }
  .staff-panel h2 { margin:0 0 8px; font-size:16px; color:var(--maroon-700); }
  .staff-grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:8px; }
  .staff-card { border:1px solid #f0f0f0; border-radius:8px; padding:8px 10px; background:#fafafa; }
  .staff-name { font-weight:600; color:var(--maroon-700); font-size:14px; }
  .staff-area { font-size:11px; color:#6b7280; margin-top:2px; }
  .staff-status { margin-top:6px; font-weight:600; font-size:12px; }
  .recent { border:1px solid #eee; border-radius:10px; padding:12px; background:#fff; }
  .recent h2 { margin:0 0 8px; font-size:16px; color:var(--maroon-700); }
  .request-list { display:flex; flex-direction:column; gap:8px; }
  .request-card { border:1px solid #f0f0f0; border-radius:8px; padding:10px; }
  .request-meta { display:flex; gap:12px; font-size:12px; color:#555; flex-wrap:wrap; margin-top:6px; }
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
    <div class="brand">RCC Admin</div>
    <div class="profile">
      
      <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main class="container">
    <section class="actions" aria-label="Admin actions">
      <a class="btn" href="/ERS/meso/information.php">Information</a>
      <a class="btn"  href="/ERS/meso/staff.php">Staffs</a>
      <a class="btn" href="/ERS/meso/workRequest.php" style="position: relative;">
        Work Request
        <?php 
          $pendingCount = $statusSummary['pending'] + $statusSummary['waiting for staff'] + $statusSummary['in progress'];
          if ($pendingCount > 0): 
        ?>
          <span style="position: absolute; top: -6px; right: -6px; background: #dc2626; color: #fff; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;"><?php echo $pendingCount; ?></span>
        <?php endif; ?>
      </a>
      <button class="btn" type="button">Reports</button>
    </section>
    <div class="dashboard-grid">
      <div class="dashboard-calendar">
        <?php require_once __DIR__ . '/../components/calendar.php'; ?>
      </div>
      <div class="dashboard-side">
        <section class="stats" aria-label="Work request overview">
          <div class="stat-card">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)$statusSummary['pending']); ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Waiting for Staff</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)$statusSummary['waiting for staff']); ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">In Progress</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)$statusSummary['in progress']); ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Completed</div>
            <div class="stat-value"><?php echo htmlspecialchars((string)$statusSummary['completed']); ?></div>
          </div>
        </section>
        <section class="deadline-panel" aria-label="Deadline alerts">
          <div class="deadline-header">
            <h2>Deadline Alerts</h2>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
              <span class="deadline-chip overdue">Overdue: <?php echo htmlspecialchars((string)$deadlineCounts['overdue']); ?></span>
              <span class="deadline-chip due">Due Soon: <?php echo htmlspecialchars((string)$deadlineCounts['due_soon']); ?></span>
            </div>
          </div>
          <?php if (($deadlineCounts['overdue'] + $deadlineCounts['due_soon']) === 0): ?>
            <div class="deadline-empty">No approaching deadlines detected at the moment.</div>
          <?php else: ?>
            <div class="deadline-list">
              <?php foreach (['overdue','due_soon'] as $state): ?>
                <?php foreach (array_slice($deadlineAlerts[$state], 0, 3) as $alert): ?>
                  <?php $meta = $alert['deadline_meta']; ?>
                  <div class="deadline-item <?php echo $state; ?>">
                    <div class="request-header">
                      <div class="request-id">Request #<?php echo htmlspecialchars((string)($alert['id'] ?? '')); ?></div>
                      <span class="deadline-badge <?php echo $state; ?>"><?php echo $state === 'overdue' ? 'Overdue' : 'Due Soon'; ?></span>
                    </div>
                    <div class="deadline-meta">
                      <span><strong>Requester:</strong> <?php echo htmlspecialchars((string)($alert['requesters_name'] ?? 'N/A')); ?></span>
                      <span><strong>Department:</strong> <?php echo htmlspecialchars((string)($alert['department'] ?? 'N/A')); ?></span>
                      <span><strong>Staff:</strong> <?php echo $alert['staff_assigned'] ? htmlspecialchars((string)$alert['staff_assigned']) : 'Unassigned'; ?></span>
                    </div>
                    <div class="deadline-meta">
                      <span><strong>Deadline:</strong> <?php echo htmlspecialchars((string)($meta['deadline_display'] ?? 'Not set')); ?></span>
                      <span><strong>Time Remaining:</strong> <?php echo htmlspecialchars((string)($meta['human_delta'] ?? 'N/A')); ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
        <section class="staff-panel" aria-label="Staff availability">
          <h2>Staff Availability</h2>
          <?php if (!$staffList): ?>
            <div style="color:#6b7280;">No staff records found.</div>
          <?php else: ?>
            <div class="staff-grid">
              <?php foreach ($staffList as $staff): 
                  $name = (string)($staff['name'] ?? '');
                  $area = (string)($staff['area_of_work'] ?? '');
                  $statusLabel = derive_staff_display_status($name, $manualStaffStatuses, $busyStaffMap);
                  $statusClass = 'available';
                  $statusLower = strtolower($statusLabel);
                  if ($statusLower === 'assigned work') { $statusClass = 'assigned'; }
                  elseif ($statusLower === 'on leave') { $statusClass = 'leave'; }
                  elseif ($statusLower === 'absence') { $statusClass = 'absence'; }
              ?>
                <div class="staff-card">
                  <div class="staff-name"><?php echo htmlspecialchars($name); ?></div>
                  <?php if ($area !== ''): ?><div class="staff-area"><?php echo htmlspecialchars($area); ?></div><?php endif; ?>
                  <div class="staff-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <p style="margin-top:12px; color:#6b7280; font-size:13px;">Staff marked as On Leave or Absence (by themselves) or currently assigned to a task will be hidden from the assignment list.</p>
        </section>
        <section class="recent" aria-label="Recent work requests">
          <h2>Recent Work Requests</h2>
          <?php if (!$recentRequests): ?>
            <div style="color:#6b7280;font-size:14px;">No work requests found.</div>
          <?php else: ?>
            <div class="request-list">
              <?php foreach ($recentRequests as $req): ?>
                <div class="request-card">
                  <div class="request-header">
                    <div class="request-id">Request #<?php echo htmlspecialchars((string)($req['id'] ?? '')); ?></div>
                    <?php echo wr_status_badge((string)($req['status'] ?? 'Pending')); ?>
                  </div>
                  <div class="request-meta">
                    <span><strong>Requester:</strong> <?php echo htmlspecialchars((string)($req['requesters_name'] ?? 'N/A')); ?></span>
                    <span><strong>Department:</strong> <?php echo htmlspecialchars((string)($req['department'] ?? 'N/A')); ?></span>
                    <span><strong>Type:</strong> <?php echo htmlspecialchars((string)($req['type_of_request'] ?? 'N/A')); ?></span>
                    <span><strong>Date:</strong> <?php echo fmt_date_short($req['date_requested'] ?? null); ?></span>
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

