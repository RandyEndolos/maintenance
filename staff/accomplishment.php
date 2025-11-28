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
    // Try parsing as DateTime (handles ISO timestamps and date strings)
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
  } catch (Throwable $e) {
    // If parsing fails, return the original string
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
  .name { font-weight: 600; color: var(--maroon-700); }
  .container { max-width: 1100px; margin: 20px auto; padding: 0 16px; }
  .actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
  .btn { display: inline-block; text-decoration: none; text-align: center; padding: 10px 14px; border-radius: 10px; border: 1px solid #e5e5e5; background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .15s ease, border-color .15s ease, transform .1s ease; font-size: 14px; }
  .btn:hover { background: #fff7f8; border-color: var(--maroon-400); }
  .btn:active { transform: translateY(1px); }
  .btn-primary { background: var(--maroon-700); color: #fff; border-color: var(--maroon-700); }
  .btn-primary:hover { background: var(--maroon-600); border-color: var(--maroon-600); }
  @media (max-width: 640px) { .actions { grid-template-columns: 1fr; } }
  .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
  .header-section h1 { margin: 0; font-size: 24px; color: var(--maroon-700); }
  .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
  .card { border: 1px solid #eee; border-radius: 12px; padding: 16px; background: #fff; overflow-x: auto; }
  .table-container { width: 100%; overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  thead { background: var(--offwhite); }
  th { padding: 12px; text-align: left; font-weight: 700; color: var(--maroon-700); border-bottom: 2px solid var(--maroon-400); font-size: 14px; }
  td { padding: 12px; border-bottom: 1px solid #e5e5e5; font-size: 14px; color: var(--text); }
  tbody tr:hover { background: #fff7f8; }
  tbody tr:last-child td { border-bottom: none; }
  .empty-state { text-align: center; padding: 40px 20px; color: #6b7280; }
  .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
  .stats { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
  .stat-card { flex: 1; min-width: 150px; padding: 16px; background: var(--offwhite); border-radius: 10px; border: 1px solid #e5e5e5; }
  .stat-value { font-size: 32px; font-weight: 700; color: var(--maroon-700); }
  .stat-label { font-size: 14px; color: #6b7280; margin-top: 4px; }
  
  .staff-name { color: #6b7280; font-size: 16px; font-weight: 600; }
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
      <a class="btn" href="/ERS/staff/dashboard.php">Back to Home</a>
      <a class="btn" href="/ERS/staff/information.php">Information</a>
      <a class="btn" href="/ERS/staff/pendingtask.php">Pending Task</a>
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
      
      // Set up PDF document
      doc.setFontSize(20);
      doc.setTextColor(90, 15, 27); // Maroon color
      doc.setFont(undefined, 'bold');
      doc.text('My Accomplishments', 14, 20);
      
      doc.setFontSize(14);
      doc.setTextColor(90, 15, 27);
      doc.setFont(undefined, 'bold');
      doc.text('Staff: <?php echo addslashes($displayName); ?>', 14, 30);
      
      doc.setFontSize(11);
      doc.setTextColor(0, 0, 0);
      doc.setFont(undefined, 'normal');
      doc.text('Generated: ' + new Date().toLocaleDateString(), 14, 38);
      
      let yPos = 48;
      const pageHeight = doc.internal.pageSize.height;
      const margin = 14;
      const lineHeight = 8;
      
      <?php if (!empty($completedTasks)): ?>
        // Table headers
        const headers = ['Date Completed', 'Requester Name', 'Task Description', 'Location'];
        const colWidths = [45, 40, 70, 35]; // Column widths in mm
        
        function drawTableHeader() {
          doc.setFontSize(12);
          doc.setFont(undefined, 'bold');
          doc.setTextColor(90, 15, 27); // Maroon color
          let xPos = margin;
          headers.forEach((header, idx) => {
            doc.text(header, xPos, yPos);
            xPos += colWidths[idx];
          });
          yPos += lineHeight + 3;
          
          // Draw header line
          doc.setDrawColor(164, 43, 67); // Maroon color
          doc.setLineWidth(0.5);
          doc.line(margin, yPos - 2, margin + colWidths.reduce((a, b) => a + b, 0), yPos - 2);
          yPos += 2;
        }
        
        <?php foreach ($tasksByMonth as $monthKey => $monthData): ?>
          // Check if we need a new page before adding month section
          if (yPos > pageHeight - 50) {
            doc.addPage();
            yPos = 20;
          }
          
          // Month header
          doc.setFontSize(16);
          doc.setFont(undefined, 'bold');
          doc.setTextColor(90, 15, 27); // Maroon color
          doc.text('<?php echo addslashes($monthData['label']); ?>', margin, yPos);
          yPos += lineHeight + 5;
          
          // Draw month separator line
          doc.setDrawColor(164, 43, 67);
          doc.setLineWidth(0.8);
          doc.line(margin, yPos - 2, margin + colWidths.reduce((a, b) => a + b, 0), yPos - 2);
          yPos += 5;
          
          // Draw table header for this month
          drawTableHeader();
          
          // Table rows for this month
          doc.setFontSize(10);
          doc.setFont(undefined, 'normal');
          doc.setTextColor(0, 0, 0);
          
          <?php foreach ($monthData['tasks'] as $task): ?>
            // Check if we need a new page
            if (yPos > pageHeight - 20) {
              doc.addPage();
              yPos = 20;
              // Redraw month header and table header on new page
              doc.setFontSize(16);
              doc.setFont(undefined, 'bold');
              doc.setTextColor(90, 15, 27);
              doc.text('<?php echo addslashes($monthData['label']); ?> (continued)', margin, yPos);
              yPos += lineHeight + 5;
              doc.setDrawColor(164, 43, 67);
              doc.setLineWidth(0.8);
              doc.line(margin, yPos - 2, margin + colWidths.reduce((a, b) => a + b, 0), yPos - 2);
              yPos += 5;
              drawTableHeader();
              doc.setFontSize(10);
              doc.setFont(undefined, 'normal');
              doc.setTextColor(0, 0, 0);
            }
            
            const rowData = [
              '<?php echo addslashes(formatDateTime((string)($task['date_finish'] ?? ''), (string)($task['time_finish'] ?? ''))); ?>',
              '<?php echo addslashes((string)($task['requesters_name'] ?? 'N/A')); ?>',
              '<?php echo addslashes(str_replace(["\r", "\n"], " ", (string)($task['description_of_work'] ?? 'N/A'))); ?>',
              '<?php echo addslashes((string)($task['location'] ?? 'N/A')); ?>'
            ];
            
            let xPos = margin;
            let maxHeight = lineHeight;
            
            rowData.forEach((cell, idx) => {
              // Split text to fit column width
              const lines = doc.splitTextToSize(cell, colWidths[idx] - 2);
              const cellHeight = lines.length * lineHeight;
              if (cellHeight > maxHeight) maxHeight = cellHeight;
              
              // Draw cell text
              doc.text(lines, xPos + 1, yPos);
              xPos += colWidths[idx];
            });
            
            // Draw row border
            doc.setDrawColor(200, 200, 200);
            doc.setLineWidth(0.1);
            doc.line(margin, yPos + maxHeight + 1, margin + colWidths.reduce((a, b) => a + b, 0), yPos + maxHeight + 1);
            
            yPos += maxHeight + 3;
          <?php endforeach; ?>
          
          // Add space between months
          yPos += 10;
        <?php endforeach; ?>
      <?php else: ?>
        doc.setFontSize(12);
        doc.text('No completed tasks yet.', margin, yPos);
      <?php endif; ?>
      
      // Save the PDF
      doc.save('Accomplishments_<?php echo addslashes(str_replace(' ', '_', $displayName)); ?>_' + new Date().toISOString().split('T')[0] + '.pdf');
    }
  </script>
</body>
</html>

