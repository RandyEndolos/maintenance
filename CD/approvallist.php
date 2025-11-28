<?php
declare(strict_types=1);

session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'campus_director') {
  header('Location: loginCD.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';

function parse_report_extra($value): array {
  if (is_array($value)) return $value;
  if (is_string($value) && $value !== '') {
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return $decoded;
  }
  return [];
}

function fmt_date(?string $value): string {
  if (!$value || trim($value) === '') return 'N/A';
  try {
    return (new DateTimeImmutable($value))->format('M d, Y g:i A');
  } catch (Throwable $e) {
    return htmlspecialchars($value);
  }
}

$approvals = [];
$disapprovals = [];

try {
  $reports = supabase_request('GET', 'meso_reports', null, [
    'select' => 'id,form_title,control_no,created_at,extra',
    'order' => 'created_at.desc',
    'limit' => 200,
  ]);
  if (!is_array($reports)) {
    $reports = [];
  }
} catch (Throwable $e) {
  $reports = [];
}

foreach ($reports as $report) {
  $extra = parse_report_extra($report['extra'] ?? null);
  $status = strtolower((string)($extra['cd_status'] ?? ''));
  if (in_array($status, ['approved','disapproved'], true)) {
    $entry = [
      'type' => 'Report',
      'reference' => 'Control #' . ((string)($report['control_no'] ?? $report['id'])),
      'title' => (string)($report['form_title'] ?? 'Untitled report'),
      'status' => ucfirst($status),
      'date' => fmt_date($extra['cd_signed_at'] ?? $report['created_at'] ?? ''),
      'note' => (string)($extra['cd_note'] ?? ''),
    ];
    if ($status === 'approved') {
      $approvals[] = $entry;
    } else {
      $disapprovals[] = $entry;
    }
  }
}

try {
  $requests = supabase_request('GET', 'work_request', null, [
    'select' => 'id,requesters_name,department,type_of_request,status,date_requested,campus_director_signature,description_of_work',
    'order' => 'date_requested.desc',
    'limit' => 200,
  ]);
  if (!is_array($requests)) {
    $requests = [];
  }
} catch (Throwable $e) {
  $requests = [];
}

foreach ($requests as $req) {
  $status = strtolower(trim((string)($req['status'] ?? '')));
  $hasSignature = trim((string)($req['campus_director_signature'] ?? '')) !== '';
  $entry = [
    'type' => 'Work Request',
    'reference' => 'Request #' . (string)$req['id'],
    'title' => (string)($req['requesters_name'] ?? 'Unknown requester'),
    'status' => '',
    'date' => fmt_date($req['date_requested'] ?? ''),
    'note' => '',
  ];
  if ($hasSignature) {
    $entry['status'] = 'Director Approved';
    $entry['note'] = (string)$req['type_of_request'];
    $approvals[] = $entry;
  } elseif ($status === 'director disapproved') {
    $entry['status'] = 'Director Disapproved';
    $entry['note'] = 'See description for appended note.';
    $disapprovals[] = $entry;
  }
}

function sort_by_date(array &$list): void {
  usort($list, function(array $a, array $b): int {
    return strcmp($b['date'], $a['date']);
  });
}

sort_by_date($approvals);
sort_by_date($disapprovals);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Approval List</title>
  <style>
    :root {
      --maroon-700: #5a0f1b;
      --gray-50: #f8f9fb;
      --gray-200: #e5e7eb;
      --gray-500: #6b7280;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Roboto, Arial, sans-serif; background: var(--gray-50); }
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      padding: 16px 24px;
      background: #fff;
      border-bottom: 1px solid var(--gray-200);
      position: sticky;
      top: 0;
      z-index: 5;
    }
    .brand { font-weight: 700; color: var(--maroon-700); }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 8px 14px;
      border-radius: 10px;
      border: 1px solid var(--maroon-700);
      color: var(--maroon-700);
      text-decoration: none;
      font-weight: 600;
    }
    .btn:hover { background: var(--maroon-700); color: #fff; }
    main {
      max-width: 1200px;
      margin: 0 auto;
      padding: 24px 16px 40px;
    }
    .card {
      background: #fff;
      border-radius: 18px;
      padding: 20px;
      border: 1px solid var(--gray-200);
      margin-bottom: 24px;
    }
    .card h2 { margin-top: 0; color: var(--maroon-700); }
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th, td {
      border: 1px solid var(--gray-200);
      padding: 10px;
      text-align: left;
    }
    th {
      background: #fef3f3;
      color: var(--maroon-700);
    }
    .empty { color: var(--gray-500); font-style: italic; }
    .search {
      width: 100%;
      padding: 10px;
      border-radius: 10px;
      border: 1px solid var(--gray-200);
      margin-bottom: 16px;
      font: inherit;
    }
  </style>
</head>
<body>
  <header>
    <div class="brand">Approval history</div>
    <a class="btn" href="dashboard.php">Back to dashboard</a>
  </header>
  <main>
    <section class="card">
      <h2>Approved papers</h2>
      <input class="search" type="search" placeholder="Search approvals..." data-table="approvals">
      <?php if (!$approvals): ?>
        <p class="empty">No approvals recorded yet.</p>
      <?php else: ?>
        <div class="table-wrapper">
          <table id="approvals-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Status</th>
                <th>Date</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($approvals as $row): ?>
                <tr>
                  <td><?php echo htmlspecialchars($row['type']); ?></td>
                  <td><?php echo htmlspecialchars($row['reference']); ?></td>
                  <td><?php echo htmlspecialchars($row['title']); ?></td>
                  <td><?php echo htmlspecialchars($row['status']); ?></td>
                  <td><?php echo htmlspecialchars($row['date']); ?></td>
                  <td><?php echo htmlspecialchars($row['note']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Disapproved papers</h2>
      <input class="search" type="search" placeholder="Search disapprovals..." data-table="disapprovals">
      <?php if (!$disapprovals): ?>
        <p class="empty">No disapproved items recorded.</p>
      <?php else: ?>
        <div class="table-wrapper">
          <table id="disapprovals-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Status</th>
                <th>Date</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($disapprovals as $row): ?>
                <tr>
                  <td><?php echo htmlspecialchars($row['type']); ?></td>
                  <td><?php echo htmlspecialchars($row['reference']); ?></td>
                  <td><?php echo htmlspecialchars($row['title']); ?></td>
                  <td><?php echo htmlspecialchars($row['status']); ?></td>
                  <td><?php echo htmlspecialchars($row['date']); ?></td>
                  <td><?php echo htmlspecialchars($row['note']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <script>
    document.querySelectorAll('.search').forEach((input) => {
      input.addEventListener('input', () => {
        const term = input.value.toLowerCase();
        const tableId = input.dataset.table === 'approvals' ? 'approvals-table' : 'disapprovals-table';
        const table = document.getElementById(tableId);
        if (!table) return;
        table.querySelectorAll('tbody tr').forEach((row) => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.includes(term) ? '' : 'none';
        });
      });
    });
  </script>
</body>
</html>

