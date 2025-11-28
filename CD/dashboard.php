<?php
declare(strict_types=1);

session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'campus_director') {
  header('Location: loginCD.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';

$displayName = (string)($user['name'] ?? 'Campus Director');
$signatureImage = (string)($user['signature_image'] ?? '');

function refresh_campus_director_profile(array &$user): array {
  try {
    $query = ['select' => 'id,name,signature_image,profile_image,department', 'limit' => 1];
    if (isset($user['id']) && $user['id'] !== null) {
      $query['id'] = 'eq.' . (string)$user['id'];
    } elseif (isset($user['email']) && $user['email'] !== '') {
      $query['email'] = 'eq.' . (string)$user['email'];
    } else {
      return $user;
    }
    $rows = supabase_request('GET', 'users', null, $query);
    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
      foreach (['name', 'signature_image', 'profile_image', 'department'] as $key) {
        if (isset($rows[0][$key]) && (string)$rows[0][$key] !== '') {
          $user[$key === 'profile_image' ? 'avatar' : $key] = $rows[0][$key];
        }
      }
    }
  } catch (Throwable $e) {
    // keep session data
  }
  $_SESSION['user'] = $user;
  return $user;
}

$user = refresh_campus_director_profile($user);
$displayName = (string)($user['name'] ?? $displayName);
$signatureImage = (string)($user['signature_image'] ?? $signatureImage);

function parse_report_extra($value): array {
  if (is_array($value)) {
    return $value;
  }
  if (is_string($value) && $value !== '') {
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
      return $decoded;
    }
  }
  return [];
}

$reportRows = [];
try {
  $reportRows = supabase_request('GET', 'meso_reports', null, [
    'select' => 'id,form_title,control_no,created_at,updated_at,extra',
    'order' => 'created_at.desc',
    'limit' => 50,
  ]);
  if (!is_array($reportRows)) {
    $reportRows = [];
  }
} catch (Throwable $e) {
  $reportRows = [];
}

$pendingReportRows = array_values(array_filter($reportRows, function(array $row): bool {
  $extra = parse_report_extra($row['extra'] ?? null);
  $status = strtolower((string)($extra['cd_status'] ?? 'pending'));
  return $status === '' || $status === 'pending';
}));
$recentPendingReports = array_slice($pendingReportRows, 0, 4);

$workRequestRows = [];
try {
  $workRequestRows = supabase_request('GET', 'work_request', null, [
    'select' => 'id,requesters_name,department,type_of_request,status,date_requested,campus_director_signature',
    'order' => 'date_requested.desc',
    'limit' => 100,
  ]);
  if (!is_array($workRequestRows)) {
    $workRequestRows = [];
  }
} catch (Throwable $e) {
  $workRequestRows = [];
}

$pendingWorkRows = array_values(array_filter($workRequestRows, function(array $row): bool {
  $status = strtolower(trim((string)($row['status'] ?? '')));
  $hasSignature = trim((string)($row['campus_director_signature'] ?? '')) !== '';
  return !$hasSignature && $status !== 'director disapproved';
}));
$recentPendingWork = array_slice($pendingWorkRows, 0, 4);

function fmt_short_date(?string $value): string {
  if (!$value || trim($value) === '') {
    return 'N/A';
  }
  try {
    return (new DateTimeImmutable($value))->format('M d, Y');
  } catch (Throwable $e) {
    return htmlspecialchars($value);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CD Dashboard</title>
  <style>
    :root {
      --maroon-900: #3b0710;
      --maroon-700: #5a0f1b;
      --maroon-500: #8c1d2f;
      --gold-400: #fbcf67;
      --gray-50: #faf7f7;
      --gray-100: #f3f4f6;
      --gray-300: #e5e7eb;
      --gray-500: #6b7280;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Roboto, Arial, sans-serif;
      background: var(--gray-50);
      color: #1f2933;
    }
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px 24px;
      background: #fff;
      border-bottom: 1px solid var(--gray-300);
      position: sticky;
      top: 0;
      z-index: 5;
    }
    .brand { font-size: 20px; font-weight: 700; color: var(--maroon-700); }
    .profile {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid var(--maroon-500);
      background: var(--gray-100);
    }
    .name { font-weight: 600; color: var(--maroon-700); }
    .btn {
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 16px;
      border-radius: 10px;
      border: 1px solid var(--maroon-500);
      font-weight: 600;
      color: var(--maroon-700);
      background: #fff;
      cursor: pointer;
      transition: background .15s ease, color .15s ease;
    }
    .btn:hover { background: var(--maroon-500); color: #fff; }
    main {
      max-width: 1200px;
      margin: 0 auto;
      padding: 24px 16px 40px;
    }
    .panels {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 18px;
      margin-bottom: 24px;
    }
    .panel {
      background: #fff;
      border-radius: 16px;
      padding: 24px;
      text-decoration: none;
      border: 1px solid var(--gray-300);
      position: relative;
      overflow: hidden;
      transition: transform .15s ease, border-color .15s ease;
    }
    .panel:hover {
      transform: translateY(-4px);
      border-color: var(--maroon-500);
    }
    .panel h2 {
      margin: 0;
      font-size: 20px;
      color: var(--maroon-700);
    }
    .panel p {
      margin: 6px 0 0;
      color: var(--gray-500);
      font-size: 14px;
    }
    .badge {
      position: absolute;
      top: 18px;
      right: 18px;
      background: var(--maroon-700);
      color: #fff;
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 18px;
    }
    .actions-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      margin-bottom: 28px;
    }
    .card {
      background: #fff;
      border-radius: 16px;
      padding: 20px;
      border: 1px solid var(--gray-300);
    }
    .card h3 {
      margin: 0 0 12px;
      color: var(--maroon-700);
    }
    .list {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .list-item {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--gray-100);
      flex-wrap: wrap;
    }
    .list-item:last-child { border-bottom: none; padding-bottom: 0; }
    .label { font-weight: 600; color: var(--maroon-700); }
    .meta { color: var(--gray-500); font-size: 14px; }
    .empty {
      color: var(--gray-500);
      font-style: italic;
      font-size: 14px;
    }
    @media (max-width: 640px) {
      header { flex-direction: column; align-items: flex-start; gap: 12px; }
      .profile { width: 100%; justify-content: space-between; }
    }
  </style>
</head>
<body>
  <header>
    <div class="brand">EVSU Campus Director</div>
    <div class="profile">
      <?php if (($user['avatar'] ?? '') !== ''): ?>
        <img class="avatar" src="<?php echo htmlspecialchars('../' . $user['avatar']); ?>" alt="Profile">
      <?php else: ?>
        <div class="avatar" aria-hidden="true"></div>
      <?php endif; ?>
      <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main>
    <section class="panels">
      <a class="panel" href="reports.php">
        <div class="badge"><?php echo count($pendingReportRows); ?></div>
        <h2>Reports</h2>
        <p>Awaiting your signature</p>
      </a>
      <a class="panel" href="workrequest.php">
        <div class="badge"><?php echo count($pendingWorkRows); ?></div>
        <h2>Work Requests</h2>
        <p>For review and signature</p>
      </a>
    </section>

    <section class="actions-grid">
      <a class="btn" href="approvallist.php">Approval List</a>
      <a class="btn" href="information.php">My Information</a>
      <a class="btn" href="mesoLA.php">Leave & Absence</a>
      <a class="btn" href="../main/index.php">Switch Portal</a>
    </section>

    <section class="card">
      <h3>Reports waiting for signature</h3>
      <?php if (!$recentPendingReports): ?>
        <div class="empty">You're all caught up. No pending reports right now.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($recentPendingReports as $report): ?>
            <?php $extra = parse_report_extra($report['extra'] ?? null); ?>
            <div class="list-item">
              <div>
                <div class="label"><?php echo htmlspecialchars((string)($report['form_title'] ?? 'Untitled form')); ?></div>
                <div class="meta">
                  Control #: <?php echo htmlspecialchars((string)($report['control_no'] ?? 'N/A')); ?>
                  &middot; Filed <?php echo fmt_short_date($report['created_at'] ?? null); ?>
                </div>
              </div>
              <a class="btn" href="reports.php#report-<?php echo htmlspecialchars((string)$report['id']); ?>">Review</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card" style="margin-top: 24px;">
      <h3>Work requests awaiting approval</h3>
      <?php if (!$recentPendingWork): ?>
        <div class="empty">No work request is pending your signature.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($recentPendingWork as $req): ?>
            <div class="list-item">
              <div>
                <div class="label">Request #<?php echo htmlspecialchars((string)$req['id']); ?></div>
                <div class="meta">
                  <?php echo htmlspecialchars((string)($req['requesters_name'] ?? 'Unknown requester')); ?>
                  &middot; <?php echo htmlspecialchars((string)($req['department'] ?? '')); ?>
                  &middot; <?php echo htmlspecialchars((string)($req['type_of_request'] ?? '')); ?>
                  &middot; Filed <?php echo fmt_short_date($req['date_requested'] ?? null); ?>
                </div>
              </div>
              <a class="btn" href="workrequest.php#work-<?php echo htmlspecialchars((string)$req['id']); ?>">Review</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>

