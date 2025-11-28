<?php
declare(strict_types=1);

session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'campus_director') {
  header('Location: loginCD.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';

$errors = [];
$success = '';

function fmt_date(string $value): string {
  try {
    return (new DateTimeImmutable($value))->format('M d, Y');
  } catch (Throwable $e) {
    return htmlspecialchars($value);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['request_id'] ?? 0);
  $decision = (string)($_POST['decision'] ?? '');
  if ($id <= 0 || !in_array($decision, ['Approved','Dismissed'], true)) {
    $errors[] = 'Invalid request.';
  } else {
    try {
      supabase_request('PATCH', 'staff_leave_absence', [
        'status' => $decision,
      ], ['id' => 'eq.' . $id]);
      $success = 'Request updated.';
    } catch (Throwable $e) {
      $errors[] = 'Unable to update leave request.';
    }
  }
}

$pending = [];
$history = [];
try {
  $rows = supabase_request('GET', 'staff_leave_absence', null, [
    'select' => 'id,staff_name,type,reason,date_start,date_ended,no_of_days,status,created_at',
    'order' => 'created_at.desc',
    'limit' => 200,
  ]);
  if (!is_array($rows)) {
    $rows = [];
  }
} catch (Throwable $e) {
  $rows = [];
}

foreach ($rows as $row) {
  $status = strtolower((string)($row['status'] ?? 'pending'));
  if ($status === 'pending') {
    $pending[] = $row;
  } else {
    $history[] = $row;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Leave &amp; Absence Approvals</title>
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
      max-width: 1100px;
      margin: 0 auto;
      padding: 24px 16px 40px;
    }
    .notice {
      padding: 10px 14px;
      border-radius: 10px;
      margin-bottom: 12px;
      font-size: 14px;
    }
    .success { background: #ecfdf5; border: 1px solid #34d399; color: #065f46; }
    .error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
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
      font-size: 14px;
    }
    th, td {
      border: 1px solid var(--gray-200);
      padding: 10px;
      text-align: left;
    }
    th { background: #fff7f5; color: var(--maroon-700); }
    .actions {
      display: flex;
      gap: 8px;
    }
    .btn.small {
      padding: 6px 10px;
      border-radius: 8px;
      font-size: 12px;
    }
    .empty { color: var(--gray-500); font-style: italic; }
  </style>
</head>
<body>
  <header>
    <div class="brand">Leave and absence approvals</div>
    <a class="btn" href="dashboard.php">Back to dashboard</a>
  </header>
  <main>
    <?php if ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
      <div class="notice error"><?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>

    <section class="card">
      <h2>Pending approvals (<?php echo count($pending); ?>)</h2>
      <?php if (!$pending): ?>
        <p class="empty">No leave or absence requests pending.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table>
            <thead>
              <tr>
                <th>Staff</th>
                <th>Type</th>
                <th>Reason</th>
                <th>Dates</th>
                <th>Days</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pending as $row): ?>
                <tr>
                  <td><?php echo htmlspecialchars((string)$row['staff_name']); ?></td>
                  <td><?php echo htmlspecialchars((string)$row['type']); ?></td>
                  <td style="max-width:280px;"><?php echo nl2br(htmlspecialchars((string)$row['reason'])); ?></td>
                  <td>
                    <?php echo fmt_date((string)$row['date_start']); ?>
                    &ndash;
                    <?php echo fmt_date((string)$row['date_ended']); ?>
                  </td>
                  <td><?php echo htmlspecialchars((string)$row['no_of_days']); ?></td>
                  <td>
                    <form method="post" class="actions">
                      <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string)$row['id']); ?>">
                      <button class="btn small" type="submit" name="decision" value="Approved">Approve</button>
                      <button class="btn small" style="border-color:#b91c1c;color:#b91c1c;" type="submit" name="decision" value="Dismissed">Dismiss</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>History</h2>
      <?php if (!$history): ?>
        <p class="empty">No processed requests yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table>
            <thead>
              <tr>
                <th>Staff</th>
                <th>Type</th>
                <th>Dates</th>
                <th>Days</th>
                <th>Status</th>
                <th>Submitted</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history as $row): ?>
                <tr>
                  <td><?php echo htmlspecialchars((string)$row['staff_name']); ?></td>
                  <td><?php echo htmlspecialchars((string)$row['type']); ?></td>
                  <td><?php echo fmt_date((string)$row['date_start']); ?> &ndash; <?php echo fmt_date((string)$row['date_ended']); ?></td>
                  <td><?php echo htmlspecialchars((string)$row['no_of_days']); ?></td>
                  <td><?php echo htmlspecialchars((string)$row['status']); ?></td>
                  <td><?php echo fmt_date((string)$row['created_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>

