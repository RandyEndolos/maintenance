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

// Determine avatar URL (prefer session value, fallback to users table)
$avatarUrl = '';
$avatarKeys = ['avatar','avatar_url','photo','profile_image','profile_photo','picture','image'];
foreach ($avatarKeys as $k) {
  if (!empty($user[$k])) { $avatarUrl = (string)$user[$k]; break; }
}
if ($avatarUrl === '' && $staffName !== '') {
  try {
    $urows = supabase_request('GET', 'users', null, ['select' => 'avatar,avatar_url,photo,picture', 'name' => 'eq.' . $staffName, 'limit' => 1]);
    if (is_array($urows) && count($urows) > 0) {
      $row = $urows[0];
      foreach (['avatar_url','avatar','photo','picture'] as $k) {
        if (!empty($row[$k])) { $avatarUrl = (string)$row[$k]; break; }
      }
    }
  } catch (Throwable $e) {
    // ignore
  }
}

// Compute initials fallback
$initials = '';
if ($displayName !== '') {
  $parts = preg_split('/\s+/', trim($displayName));
  $letters = [];
  foreach ($parts as $p) {
    if ($p === '') continue;
    $letters[] = mb_strtoupper(mb_substr($p, 0, 1));
    if (count($letters) >= 2) break;
  }
  $initials = implode('', $letters);
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
  :root{
    --maroon-900:#3f0710;
    --maroon-800:#5a0f1b;
    --maroon-700:#7a1b2a;
    --maroon-500:#a42b43;
    --maroon-300:#c66a74;
    --muted:#f7f3f4;
    --card:#ffffff;
    --text:#111827;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    font-family:Inter, -apple-system, system-ui, 'Segoe UI', Roboto, Arial;
    background: linear-gradient(180deg, var(--maroon-700) 0%, var(--maroon-500) 100%);
    color:var(--text);
    min-height:100vh;
  }
  .topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:linear-gradient(90deg,var(--maroon-800),var(--maroon-700));color:#fff;box-shadow:0 2px 8px rgba(15,23,42,0.06);position:sticky;top:0;z-index:50}
  .brand{font-weight:700;font-size:18px;letter-spacing:0.2px}
  .profile{display:flex;align-items:center;gap:12px}
  .avatar{width:40px;height:40px;border-radius:999px;object-fit:cover;background:#fff3f4;border:2px solid rgba(255,255,255,0.12)}
  .name{font-weight:700;color:#fff}
  .container{
    max-width:1200px;
    margin:38px auto 28px auto;
    padding:0 20px;
    background:rgba(255,255,255,0.97);
    border-radius:18px;
    box-shadow:0 8px 32px rgba(64,7,16,0.10), 0 1.5px 0 rgba(64,7,16,0.04);
  }
  /* ensure content inside the white card respects rounding and doesn't overflow */
  .container{overflow:hidden; padding:18px 20px}
  .actions{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
    width:100%;
    margin-bottom:10px;
  }
  /* keep action buttons inside the white card and avoid overflow */
  .actions{padding:6px 0}
  .btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:12px 0;
    border-radius:10px;
    border:0;
    background:var(--maroon-700);
    color:#fff;
    font-weight:700;
    cursor:pointer;
    box-shadow:0 1px 0 rgba(0,0,0,0.04);
    transition:transform .08s ease,box-shadow .12s ease;
    width:100%;
    max-width:100%;
    font-size:1rem;
  }
  .btn, .actions a.btn, .actions button.btn{box-sizing:border-box;min-width:0}
  .actions a.btn{display:inline-block;text-align:center}
  /* prevent long text from forcing extra width */
  .btn{white-space:normal;overflow:hidden;text-overflow:ellipsis}
  .btn.secondary{background:transparent;border:1px solid rgba(124,58,58,0.12);color:var(--maroon-800);font-weight:600}
  .btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(124,58,58,0.08)}
  @media(max-width:760px){
    .actions{grid-template-columns:repeat(2,1fr)}
  }
  @media(max-width:460px){
    .actions{grid-template-columns:1fr}
  }

  .dashboard-grid{
    display:grid;
    grid-template-columns:2fr 1fr;
    gap:18px;
    align-items:start;
    margin-top:22px;
  }
  .dashboard-calendar{
    min-width:0;
    background:var(--card);
    border-radius:16px;
    padding:18px 16px 16px 16px;
    box-shadow:0 6px 18px rgba(16,24,40,0.07);
    border:1.5px solid var(--maroon-300);
  }
  .dashboard-side{
    display:flex;
    flex-direction:column;
    gap:18px;
  }

  .card{
    background:var(--card);
    border-radius:14px;
    padding:16px;
    border:1.5px solid var(--maroon-300);
    box-shadow:0 6px 18px rgba(16,24,40,0.06);
  }
  .status-card{
    display:flex;
    flex-direction:column;
    gap:14px;
    background:var(--card);
    border-radius:14px;
    padding:16px 14px 14px 14px;
    border:1.5px solid var(--maroon-300);
    box-shadow:0 6px 18px rgba(124,58,58,0.07);
  }
  .status-row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
  .status-title{font-weight:700;color:var(--maroon-900)}

  .status-pill{display:inline-block;padding:8px 14px;border-radius:999px;font-weight:700;font-size:13px}
  .status-pill.available{background:#eefdf5;color:#065f46}
  .status-pill.assigned{background:#eef2ff;color:#3730a3}
  .status-pill.leave{background:#fff7ed;color:#92400e}
  .status-pill.absence{background:#fff1f2;color:#831843}

  .status-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .status-form select{padding:10px;border-radius:8px;border:1px solid rgba(15,23,42,0.06);font:inherit}
  .status-note{font-size:13px;color:#6b7280}

  .deadline-card{
    background:var(--card);
    border-radius:14px;
    padding:16px 14px 14px 14px;
    border:1.5px solid var(--maroon-300);
    box-shadow:0 6px 18px rgba(124,58,58,0.07);
  }
  .deadline-card h2{margin:0;font-size:16px;color:var(--maroon-800)}
  .deadline-list{display:flex;flex-direction:column;gap:10px;margin-top:10px}
  .deadline-item{display:flex;flex-direction:column;gap:8px;padding:12px;border-radius:10px;border:1px solid rgba(15,23,42,0.04);background:linear-gradient(180deg,#fff,#fff)}
  .deadline-item.overdue{border-color:rgba(185,28,28,0.12);background:linear-gradient(180deg,#fff5f5,#fff)}
  .deadline-item.due_soon{border-color:rgba(202,138,4,0.12);background:linear-gradient(180deg,#fffbeb,#fff)}
  .deadline-meta{font-size:13px;color:#374151;display:flex;flex-wrap:wrap;gap:10px}
  .deadline-badge{padding:4px 8px;border-radius:999px;font-weight:800;font-size:11px}
  .deadline-badge.overdue{background:#fee2e2;color:#991b1b}
  .deadline-badge.due_soon{background:#fff7ed;color:#92400e}

  .deadlines-empty{color:#6b7280;font-size:14px}

  @media(max-width:900px){
    .dashboard-grid{grid-template-columns:1fr}
    .container{margin:18px auto}
  }
  /* Maroon background for page */
  html{background:var(--maroon-700);}
</style>
</head>
<body>
  <header class="topbar">
    <div class="brand">RCC Staff</div>
    <div class="profile">
      <?php if ($avatarUrl !== ''): ?>
        <img class="avatar" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" onerror="this.style.display='none'; this.parentElement.querySelector('.avatar.initials').style.display='flex'">
      <?php else: ?>
        <div class="avatar initials"><?php echo htmlspecialchars($initials); ?></div>
      <?php endif; ?>
      <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main class="container">
    <section class="actions" aria-label="Staff actions">
      <a class="btn" href="/maintenance/staff/information.php">Information</a>
      <a class="btn" href="/maintenance/staff/pendingtask.php">Pending Task</a>
      <a class="btn" href="/maintenance/staff/accomplishment.php">Accomplishments</a>
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

