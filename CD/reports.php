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
$success = '';
$errors = [];

$indicators = [
  ['id' => 'core', 'label' => 'Core functions'],
  ['id' => 'infrastructure', 'label' => 'Infrastructure support'],
  ['id' => 'facility', 'label' => 'Facility management & maintenance'],
  ['id' => 'strategic', 'label' => 'Strategic functions'],
  ['id' => 'institutional', 'label' => 'Support to institutional goals'],
  ['id' => 'support', 'label' => 'Support functions'],
  ['id' => 'office', 'label' => 'Office and record maintenance'],
  ['id' => 'other', 'label' => 'Other accomplishments'],
  ['id' => 'safety', 'label' => 'Safety measures'],
];

function parse_report_extra($value): array {
  if (is_array($value)) { return $value; }
  if (is_string($value) && $value !== '') {
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
      return $decoded;
    }
  }
  return [];
}

function uploads_directory(): string {
  $dir = realpath(__DIR__ . '/../uploads');
  if ($dir === false) {
    $dir = __DIR__ . '/../uploads';
  }
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  return $dir;
}

function handle_signature_upload(string $fieldName): ?string {
  if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
    return null;
  }
  $file = $_FILES[$fieldName];
  if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    return null;
  }
  $tmp = (string)($file['tmp_name'] ?? '');
  $name = (string)($file['name'] ?? '');
  $size = (int)($file['size'] ?? 0);
  if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0) {
    return null;
  }
  $allowed = ['png','jpg','jpeg','gif','webp'];
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowed, true)) {
    $ext = 'png';
  }
  $safeBase = preg_replace('/[^a-z0-9_-]/i', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'signature';
  $filename = $safeBase . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
  $dest = rtrim(uploads_directory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
  if (!@move_uploaded_file($tmp, $dest)) {
    return null;
  }
  return 'uploads/' . $filename;
}

function fetch_report_by_id(int $id): ?array {
  if ($id <= 0) {
    return null;
  }
  try {
    $rows = supabase_request('GET', 'meso_reports', null, [
      'select' => '*',
      'id' => 'eq.' . $id,
      'limit' => 1,
    ]);
    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
      return $rows[0];
    }
  } catch (Throwable $e) {
  }
  return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $reportId = (int)($_POST['report_id'] ?? 0);
  $action = (string)($_POST['action'] ?? '');
  $report = fetch_report_by_id($reportId);
  if (!$report) {
    $errors[] = 'Unable to locate the selected report.';
  } else {
    $extra = parse_report_extra($report['extra'] ?? null);
    if ($action === 'approve') {
      $signaturePath = handle_signature_upload('signature_file');
      if ($signaturePath === null) {
        $errors[] = 'Signature file is required for approval.';
      } else {
        $extra['cd_status'] = 'approved';
        $extra['cd_signed_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $extra['cd_signed_by'] = $displayName;
        $extra['cd_signature_path'] = $signaturePath;
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note !== '') {
          $extra['cd_note'] = $note;
        }
        try {
          supabase_request('PATCH', 'meso_reports', [
            'extra' => $extra,
          ], ['id' => 'eq.' . $reportId]);
          $success = 'Report approved successfully.';
        } catch (Throwable $e) {
          $errors[] = 'Failed to update report record.';
        }
      }
    } elseif ($action === 'disapprove') {
      $note = trim((string)($_POST['note'] ?? ''));
      if ($note === '') {
        $errors[] = 'Reason is required to disapprove a report.';
      } else {
        $extra['cd_status'] = 'disapproved';
        $extra['cd_signed_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $extra['cd_signed_by'] = $displayName;
        $extra['cd_signature_path'] = null;
        $extra['cd_note'] = $note;
        try {
          supabase_request('PATCH', 'meso_reports', [
            'extra' => $extra,
          ], ['id' => 'eq.' . $reportId]);
          $success = 'Report marked as disapproved.';
        } catch (Throwable $e) {
          $errors[] = 'Failed to update report record.';
        }
      }
    }
  }
}

$reports = [];
try {
  $reports = supabase_request('GET', 'meso_reports', null, [
    'select' => '*',
    'order' => 'created_at.desc',
    'limit' => 100,
  ]);
  if (!is_array($reports)) {
    $reports = [];
  }
} catch (Throwable $e) {
  $reports = [];
}

$pendingReports = [];
$processedReports = [];
foreach ($reports as $row) {
  $extra = parse_report_extra($row['extra'] ?? null);
  $status = strtolower((string)($extra['cd_status'] ?? 'pending'));
  $row['_extra'] = $extra;
  if ($status === '' || $status === 'pending') {
    $pendingReports[] = $row;
  } else {
    $processedReports[] = $row;
  }
}

function render_indicator_value(array $report, string $prefix, string $id): string {
  $key = $prefix . '_' . $id;
  return trim((string)($report[$key] ?? ''));
}

function fmt_datetime(?string $value): string {
  if (!$value || trim($value) === '') {
    return 'N/A';
  }
  try {
    return (new DateTimeImmutable($value))->format('M d, Y g:i A');
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
  <title>Reports Approval</title>
  <style>
    :root {
      --maroon-700: #5a0f1b;
      --maroon-500: #8c1d2f;
      --gray-50: #f8f9fb;
      --gray-200: #e5e7eb;
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
      border: 1px solid var(--maroon-500);
      background: #fff;
      color: var(--maroon-700);
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: background .15s ease;
    }
    .btn:hover { background: var(--maroon-500); color: #fff; }
    main {
      max-width: 1200px;
      margin: 0 auto;
      padding: 24px 16px 40px;
    }
    .notice {
      padding: 12px 16px;
      border-radius: 10px;
      margin-bottom: 16px;
      font-size: 14px;
    }
    .success { background: #ecfdf5; border: 1px solid #34d399; color: #065f46; }
    .error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
    .card {
      background: #fff;
      border-radius: 18px;
      border: 1px solid var(--gray-200);
      padding: 20px;
      margin-bottom: 20px;
    }
    .card h2 {
      margin-top: 0;
      color: var(--maroon-700);
    }
    .report-card {
      border: 1px solid var(--gray-200);
      border-radius: 16px;
      padding: 18px;
      margin-bottom: 16px;
      background: #fff;
    }
    .report-header {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .report-title { font-size: 18px; font-weight: 700; color: var(--maroon-700); }
    .meta { color: var(--gray-500); font-size: 14px; }
    details {
      margin-top: 12px;
      border-radius: 12px;
      border: 1px solid var(--gray-200);
      background: #fafafa;
      padding: 12px;
    }
    summary {
      cursor: pointer;
      font-weight: 600;
      color: var(--maroon-700);
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
      font-size: 13px;
    }
    th, td {
      border: 1px solid var(--gray-200);
      padding: 8px;
      vertical-align: top;
    }
    th { background: #fff; }
    form {
      margin-top: 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    textarea, input[type="file"] {
      width: 100%;
      border: 1px solid var(--gray-200);
      border-radius: 10px;
      padding: 10px;
      font: inherit;
      resize: vertical;
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .btn.primary {
      background: var(--maroon-700);
      color: #fff;
      border-color: var(--maroon-700);
    }
    .btn.secondary {
      border-color: #b91c1c;
      color: #b91c1c;
    }
    .empty {
      color: var(--gray-500);
      font-style: italic;
      margin: 12px 0;
    }
  </style>
</head>
<body>
  <header>
    <div class="brand">Reports needing approval</div>
    <div class="actions">
      <a class="btn" href="dashboard.php">Back to dashboard</a>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main>
    <?php if ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
      <div class="notice error"><?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>

    <section class="card">
      <h2>Pending reports (<?php echo count($pendingReports); ?>)</h2>
      <?php if (!$pendingReports): ?>
        <div class="empty">No reports are waiting for your signature.</div>
      <?php else: ?>
        <?php foreach ($pendingReports as $report): ?>
          <article class="report-card" id="report-<?php echo htmlspecialchars((string)$report['id']); ?>">
            <div class="report-header">
              <div>
                <div class="report-title"><?php echo htmlspecialchars((string)($report['form_title'] ?? 'Untitled form')); ?></div>
                <div class="meta">
                  Control #: <?php echo htmlspecialchars((string)($report['control_no'] ?? 'N/A')); ?>
                  &middot; Revision: <?php echo htmlspecialchars((string)($report['revision_no'] ?? '')); ?>
                  &middot; Filed: <?php echo fmt_datetime($report['created_at'] ?? null); ?>
                </div>
              </div>
              <div class="meta">
                Prepared by: <?php echo htmlspecialchars((string)($report['prepared_by'] ?? '')); ?><br>
                Approved by: <?php echo htmlspecialchars((string)($report['approved_by'] ?? '')); ?>
              </div>
            </div>
            <details>
              <summary>View report contents</summary>
              <table>
                <thead>
                  <tr>
                    <th>Success Indicator</th>
                    <th>Targets &amp; measures</th>
                    <th>Actual accomplishments</th>
                    <th>Drive link</th>
                    <th>Best practices / catch up plan</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($indicators as $row): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($row['label']); ?></td>
                      <td><?php echo nl2br(htmlspecialchars(render_indicator_value($report, 'targets', $row['id']))); ?></td>
                      <td><?php echo nl2br(htmlspecialchars(render_indicator_value($report, 'actual', $row['id']))); ?></td>
                      <td><?php echo nl2br(htmlspecialchars(render_indicator_value($report, 'drive', $row['id']))); ?></td>
                      <td><?php echo nl2br(htmlspecialchars(render_indicator_value($report, 'best', $row['id']))); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </details>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="report_id" value="<?php echo htmlspecialchars((string)$report['id']); ?>">
              <label style="font-weight:600;color:var(--maroon-700);">Add note (optional)</label>
              <textarea name="note" placeholder="Message to MESO head or requester..."><?php echo htmlspecialchars((string)($_POST['note'] ?? '')); ?></textarea>
              <div class="actions">
                <label class="btn secondary" style="gap:10px; cursor:pointer;">
                  Upload signature
                  <input type="file" name="signature_file" accept="image/*" required style="display:none;">
                </label>
              </div>
              <div class="actions">
                <button class="btn primary" type="submit" name="action" value="approve">Approve &amp; attach signature</button>
                <button class="btn secondary" type="submit" name="action" value="disapprove">Disapprove</button>
              </div>
            </form>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Processed reports</h2>
      <?php if (!$processedReports): ?>
        <div class="empty">No historical approvals yet.</div>
      <?php else: ?>
        <?php foreach ($processedReports as $report): ?>
          <?php $extra = $report['_extra']; ?>
          <article class="report-card">
            <div class="report-header">
              <div>
                <div class="report-title"><?php echo htmlspecialchars((string)($report['form_title'] ?? 'Untitled form')); ?></div>
                <div class="meta">
                  Signed <?php echo fmt_datetime($extra['cd_signed_at'] ?? null); ?>
                  &middot; Status: <?php echo ucfirst((string)($extra['cd_status'] ?? 'pending')); ?>
                </div>
              </div>
              <?php if (!empty($extra['cd_signature_path'])): ?>
                <div class="meta">Signature on file: <?php echo htmlspecialchars((string)$extra['cd_signature_path']); ?></div>
              <?php endif; ?>
            </div>
            <?php if (!empty($extra['cd_note'])): ?>
              <p class="meta">Note: <?php echo nl2br(htmlspecialchars((string)$extra['cd_note'])); ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>

