<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'staff') {
  header('Location: ../main/index.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';

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

// Fetch completed tasks for this staff member
$completedTasks = [];
$tasksByMonth = [];
try {
  $filters = [
    'select' => 'id,description_of_work,location,requesters_name,date_requested,date_start,date_finish,time_start,time_finish,type_of_request,department,status',
    'order' => 'date_finish.desc,date_start.desc',
  ];
  if ($staffName !== '') {
    $filters['staff_assigned'] = 'ilike.*' . $staffName . '*';
  }
  // Get completed tasks
  $allTasks = supabase_request('GET', 'work_request', null, $filters);
  if (is_array($allTasks)) {
    foreach ($allTasks as $task) {
      $status = strtolower(trim((string)($task['status'] ?? '')));
      if (in_array($status, ['completed', 'done'])) {
        $completedTasks[] = $task;
        
        // Group by month
        $dateFinish = (string)($task['date_finish'] ?? '');
        if (!empty($dateFinish)) {
          try {
            $date = new DateTime($dateFinish);
            $monthKey = $date->format('Y-m'); // Format: 2024-01
            $monthLabel = $date->format('F Y'); // Format: January 2024
            
            if (!isset($tasksByMonth[$monthKey])) {
              $tasksByMonth[$monthKey] = [
                'label' => $monthLabel,
                'tasks' => []
              ];
            }
            $tasksByMonth[$monthKey]['tasks'][] = $task;
          } catch (Throwable $e) {
            // If date parsing fails, add to a default month
            $monthKey = 'unknown';
            if (!isset($tasksByMonth[$monthKey])) {
              $tasksByMonth[$monthKey] = [
                'label' => 'Unknown Date',
                'tasks' => []
              ];
            }
            $tasksByMonth[$monthKey]['tasks'][] = $task;
          }
        } else {
          // No date, add to unknown
          $monthKey = 'unknown';
          if (!isset($tasksByMonth[$monthKey])) {
            $tasksByMonth[$monthKey] = [
              'label' => 'Unknown Date',
              'tasks' => []
            ];
          }
          $tasksByMonth[$monthKey]['tasks'][] = $task;
        }
      }
    }
  }
  // Sort months in descending order (newest first)
  krsort($tasksByMonth);
} catch (Throwable $e) {
  $completedTasks = [];
  $tasksByMonth = [];
}

// Format date for display (handles both date strings and timestamps)
function formatDate(?string $dateStr): string {
  if (empty($dateStr)) return 'N/A';
  try {
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
  } catch (Throwable $e) {
    return $dateStr;
  }
}

// Format datetime for display
function formatDateTime(?string $dateStr, ?string $timeStr): string {
  if (empty($dateStr)) return 'N/A';
  try {
    $dateTime = $dateStr;
    if (!empty($timeStr)) {
      $dateTime .= ' ' . $timeStr;
    }
    $dt = new DateTime($dateTime);
    return $dt->format('M d, Y h:i A');
  } catch (Throwable $e) {
    return $dateStr . (!empty($timeStr) ? ' ' . $timeStr : '');
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Accomplishments</title>
<style>
  :root {
    --maroon-900: #3f0710;
    --maroon-800: #5a0f1b;
    --maroon-700: #7a1b2a;
    --maroon-600: #8b1f2f;
    --maroon-500: #a42b43;
    --offwhite: #fbf6f6;
    --text: #222;
    --muted-text: #6b7280;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: linear-gradient(180deg,var(--offwhite),#fff 40%); color: var(--text); }
  .topbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid rgba(0,0,0,0.04); background: linear-gradient(90deg,var(--maroon-800),var(--maroon-700)); color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
  .brand { font-weight: 800; color: #fff; }
  .profile { display: flex; align-items: center; gap: 10px; }
  .name { font-weight: 600; color: #fff; }
  .container { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
  .actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
  .btn { display: inline-block; text-decoration: none; text-align: center; padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.06); background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .12s ease, border-color .12s ease, transform .12s ease; font-size: 14px; }
  .btn:hover { background: #fff7f8; border-color: var(--maroon-500); }
  .btn:active { transform: translateY(1px); }
  .btn-primary { background: var(--maroon-700); color: #fff; border-color: var(--maroon-700); }
  .btn-primary:hover { background: var(--maroon-800); border-color: var(--maroon-800); }
  @media (max-width: 760px) { .actions { grid-template-columns: repeat(2,1fr); } }
  @media (max-width: 480px) { .actions { grid-template-columns: 1fr; } }
  .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
  .header-section h1 { margin: 0; font-size: 22px; color: var(--maroon-700); }
  .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
  .card { border: 1px solid rgba(0,0,0,0.04); border-radius: 12px; padding: 16px; background: #fff; overflow-x: auto; }
  .table-container { width: 100%; overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  thead { background: rgba(244,240,240,0.7); }
  th { padding: 12px; text-align: left; font-weight: 700; color: var(--maroon-700); border-bottom: 2px solid var(--maroon-500); font-size: 14px; }
  td { padding: 12px; border-bottom: 1px solid rgba(0,0,0,0.04); font-size: 14px; color: var(--text); }
  tbody tr:hover { background: #fff7f8; }
  tbody tr:last-child td { border-bottom: none; }
  .empty-state { text-align: center; padding: 40px 20px; color: var(--muted-text); }
  .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
  .stats { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
  .stat-card { flex: 1; min-width: 150px; padding: 16px; background: linear-gradient(180deg, #fff, #fff); border-radius: 10px; border: 1px solid rgba(0,0,0,0.04); }
  .stat-value { font-size: 32px; font-weight: 700; color: var(--maroon-700); }
  .stat-label { font-size: 14px; color: var(--muted-text); margin-top: 4px; }
  
  .staff-name { color: var(--muted-text); font-size: 16px; font-weight: 600; }
  @media print {
    .topbar, .actions, .action-buttons, .btn, .stats { display: none !important; }
    body { background: #fff; }
    .container { max-width: 100%; margin: 0; padding: 20px; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; page-break-after: auto; }
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }
    .header-section { page-break-after: avoid; }
    .header-section h1 { font-size: 20px; }
    .staff-name { font-size: 14px; margin-top: 4px; }
  }
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
      <a class="btn" href="/maintenance/staff/pendingtask.php">Pending Task</a>
    </section>

    <div class="header-section">
      <div>
        <h1>My Accomplishments</h1>
        <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 16px; font-weight: 600;">Staff: <?php echo htmlspecialchars($displayName); ?></p>
      </div>
      <div class="action-buttons">
        <button class="btn btn-primary" onclick="window.print()">Print</button>
        <button class="btn btn-primary" onclick="downloadPDF()">Download PDF</button>
      </div>
    </div>

    <div class="stats">
      <div class="stat-card">
        <div class="stat-value"><?php echo count($completedTasks); ?></div>
        <div class="stat-label">Completed Tasks</div>
      </div>
    </div>

    <section class="card" aria-label="Completed tasks">
      <?php if (empty($completedTasks)): ?>
        <div class="empty-state">
          <div class="empty-state-icon">📋</div>
          <h2>No completed tasks yet</h2>
          <p>Your completed tasks will appear here once you finish assigned work.</p>
        </div>
      <?php else: ?>
        <?php foreach ($tasksByMonth as $monthKey => $monthData): ?>
          <div style="margin-bottom: 30px;">
            <h2 style="color: var(--maroon-700); font-size: 18px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid var(--maroon-400);">
              <?php echo htmlspecialchars($monthData['label']); ?>
            </h2>
            <div class="table-container">
              <table>
                <thead>
                  <tr>
                    <th>Date Completed</th>
                    <th>Requester Name</th>
                    <th>Task Description</th>
                    <th>Location</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($monthData['tasks'] as $task): ?>
                    <tr>
                      <td><?php echo formatDateTime((string)($task['date_finish'] ?? ''), (string)($task['time_finish'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string)($task['requesters_name'] ?? 'N/A')); ?></td>
                      <td><?php echo htmlspecialchars((string)($task['description_of_work'] ?? 'N/A')); ?></td>
                      <td><?php echo htmlspecialchars((string)($task['location'] ?? 'N/A')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </main>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script>
    function downloadPDF() {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF();
      doc.setFontSize(20);
      doc.setTextColor(90, 15, 27);
      doc.setFont(undefined, 'bold');
      doc.text('My Accomplishments', 14, 20);
      doc.setFontSize(14);
      doc.text('Staff: <?php echo addslashes($displayName); ?>', 14, 30);
      doc.setFontSize(11);
      doc.setTextColor(0, 0, 0);
      doc.text('Generated: ' + new Date().toLocaleDateString(), 14, 38);
      // PDF content generation code here...
    }
  </script>
</body>
</html>
