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
$success = '';
$errors = [];

try {
  $query = ['select' => 'name,signature_image', 'limit' => 1];
  if (isset($user['id']) && $user['id']) {
    $query['id'] = 'eq.' . (string)$user['id'];
  } elseif (isset($user['email']) && $user['email'] !== '') {
    $query['email'] = 'eq.' . (string)$user['email'];
  } else {
    $query = null;
  }
  if ($query !== null) {
    $rows = supabase_request('GET', 'users', null, $query);
    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
      if (!empty($rows[0]['name'])) {
        $displayName = (string)$rows[0]['name'];
        $_SESSION['user']['name'] = $displayName;
      }
      if (!empty($rows[0]['signature_image'])) {
        $signatureImage = (string)$rows[0]['signature_image'];
        $_SESSION['user']['signature_image'] = $signatureImage;
      }
    }
  }
} catch (Throwable $e) {
  // keep session data
}

function fetch_request_by_id(int $id): ?array {
  if ($id <= 0) return null;
  try {
    $rows = supabase_request('GET', 'work_request', null, [
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
  $id = (int)($_POST['request_id'] ?? 0);
  $action = (string)($_POST['action'] ?? '');
  $request = fetch_request_by_id($id);
  if (!$request) {
    $errors[] = 'Unable to locate the work request.';
  } else {
    if ($action === 'approve') {
      if ($signatureImage === '') {
        $errors[] = 'Upload your signature in Information page before approving.';
      } else {
        $update = [
          'campus_director_signature' => $signatureImage,
          'status' => 'Director Approved',
        ];
        try {
          supabase_request('PATCH', 'work_request', $update, ['id' => 'eq.' . $id]);
          $success = 'Work request approved.';
        } catch (Throwable $e) {
          $errors[] = 'Failed to update work request.';
        }
      }
    } elseif ($action === 'disapprove') {
      $note = trim((string)($_POST['note'] ?? ''));
      if ($note === '') {
        $errors[] = 'Reason is required when disapproving.';
      } else {
        $existingDescription = (string)($request['description_of_work'] ?? '');
        $stamp = (new DateTimeImmutable())->format('M d, Y g:i A');
        $noteBlock = "\n\n[Director Note - {$stamp}] {$note}";
        $update = [
          'status' => 'Director Disapproved',
          'campus_director_signature' => null,
          'description_of_work' => trim($existingDescription . $noteBlock),
        ];
        try {
          supabase_request('PATCH', 'work_request', $update, ['id' => 'eq.' . $id]);
          $success = 'Work request marked as disapproved.';
        } catch (Throwable $e) {
          $errors[] = 'Failed to update work request.';
        }
      }
    }
  }
}

$workRequests = [];
try {
  $workRequests = supabase_request('GET', 'work_request', null, [
    'select' => '*',
    'order' => 'date_requested.desc',
    'limit' => 150,
  ]);
  if (!is_array($workRequests)) {
    $workRequests = [];
  }
} catch (Throwable $e) {
  $workRequests = [];
}

// Auto-approve requests where the requester is the campus director themselves
$autoApprovedCount = 0;
foreach ($workRequests as $idx => $row) {
  $hasSignature = trim((string)($row['campus_director_signature'] ?? '')) !== '';
  $status = strtolower(trim((string)($row['status'] ?? '')));
  $requesterName = trim((string)($row['requesters_name'] ?? ''));
  
  // If pending and requester is the campus director, auto-approve
  if (!$hasSignature && $status !== 'director disapproved' && $requesterName !== '') {
    // Case-insensitive comparison of requester name with campus director name
    if (strcasecmp($requesterName, $displayName) === 0) {
      // Auto-approve if signature is available
      if ($signatureImage !== '') {
        try {
          $update = [
            'campus_director_signature' => $signatureImage,
            'status' => 'Director Approved',
          ];
          supabase_request('PATCH', 'work_request', $update, ['id' => 'eq.' . (int)$row['id']]);
          // Update the array element so it's reflected in pending/processed separation
          $workRequests[$idx]['campus_director_signature'] = $signatureImage;
          $workRequests[$idx]['status'] = 'Director Approved';
          $autoApprovedCount++;
        } catch (Throwable $e) {
          // Silently fail for auto-approval errors, continue processing
        }
      }
    }
  }
}

if ($autoApprovedCount > 0) {
  $autoApproveMsg = $autoApprovedCount === 1 
    ? '1 work request automatically approved (self-request).' 
    : "{$autoApprovedCount} work requests automatically approved (self-requests).";
  if ($success !== '') {
    $success .= ' ' . $autoApproveMsg;
  } else {
    $success = $autoApproveMsg;
  }
}

$pendingRequests = [];
$processedRequests = [];
foreach ($workRequests as $row) {
  $hasSignature = trim((string)($row['campus_director_signature'] ?? '')) !== '';
  $status = strtolower(trim((string)($row['status'] ?? '')));
  if (!$hasSignature && $status !== 'director disapproved') {
    $pendingRequests[] = $row;
  } else {
    $processedRequests[] = $row;
  }
}

function fmt_date(?string $value): string {
  if (!$value || trim($value) === '') return 'N/A';
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
  <title>Work Requests Approval</title>
  <style>
    :root {
      --maroon-700: #5a0f1b;
      --maroon-500: #8c1d2f;
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
      border: 1px solid var(--maroon-500);
      background: #fff;
      color: var(--maroon-700);
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
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
      padding: 20px;
      border: 1px solid var(--gray-200);
      margin-bottom: 24px;
    }
    .card h2 { margin-top: 0; color: var(--maroon-700); }
    .request-card {
      border: 1px solid var(--gray-200);
      border-radius: 16px;
      padding: 18px;
      margin-bottom: 16px;
      background: #fff;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
      margin-top: 12px;
    }
    .label { font-size: 12px; color: var(--gray-500); text-transform: uppercase; letter-spacing: .05em; }
    .value { font-weight: 600; color: #1f2933; }
    .img-thumb {
      max-width: 260px;
      border-radius: 12px;
      border: 1px solid var(--gray-200);
      margin-top: 10px;
    }
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
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .btn.primary { background: var(--maroon-700); color: #fff; border-color: var(--maroon-700); }
    .btn.secondary { border-color: #b91c1c; color: #b91c1c; }
    .empty { color: var(--gray-500); font-style: italic; }
  </style>
</head>
<body>
  <header>
    <div class="brand">Work requests for signature</div>
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
      <h2>Pending requests (<?php echo count($pendingRequests); ?>)</h2>
      <?php if (!$pendingRequests): ?>
        <p class="empty">No work request is awaiting signature.</p>
      <?php else: ?>
        <?php foreach ($pendingRequests as $req): ?>
          <article class="request-card" id="work-<?php echo htmlspecialchars((string)$req['id']); ?>">
            <div class="label">Request #<?php echo htmlspecialchars((string)$req['id']); ?></div>
            <div class="grid">
              <div>
                <div class="label">Requester</div>
                <div class="value"><?php echo htmlspecialchars((string)($req['requesters_name'] ?? 'N/A')); ?></div>
              </div>
              <div>
                <div class="label">Department</div>
                <div class="value"><?php echo htmlspecialchars((string)($req['department'] ?? 'N/A')); ?></div>
              </div>
              <div>
                <div class="label">Type of request</div>
                <div class="value"><?php echo htmlspecialchars((string)($req['type_of_request'] ?? '')); ?></div>
              </div>
              <div>
                <div class="label">Requested on</div>
                <div class="value"><?php echo fmt_date($req['date_requested'] ?? null); ?></div>
              </div>
              <div>
                <div class="label">Location</div>
                <div class="value"><?php echo htmlspecialchars((string)($req['location'] ?? 'N/A')); ?></div>
              </div>
              <div>
                <div class="label">Personnel needed</div>
                <div class="value"><?php echo htmlspecialchars((string)($req['no_of_personnel_needed'] ?? '')); ?></div>
              </div>
            </div>
            <div style="margin-top:12px;">
              <div class="label">Description</div>
              <div class="value" style="white-space: pre-line;"><?php echo htmlspecialchars((string)($req['description_of_work'] ?? '')); ?></div>
            </div>
            <?php if (!empty($req['image_of_work'])): ?>
              <div>
                <div class="label">Attachment</div>
                <img class="img-thumb" src="../<?php echo htmlspecialchars((string)$req['image_of_work']); ?>" alt="Work reference">
              </div>
            <?php endif; ?>
            <form method="post">
              <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string)$req['id']); ?>">
              <label style="font-weight:600;color:var(--maroon-700);">Director note</label>
              <textarea name="note" placeholder="Optional message for approval. Required for disapproval."></textarea>
              <p style="font-size:13px;color:var(--gray-500);margin:0;">
                Signature on file: <?php echo $signatureImage !== '' ? htmlspecialchars($signatureImage) : 'None (update via Information page)'; ?>
              </p>
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
      <h2>Processed requests</h2>
      <?php if (!$processedRequests): ?>
        <p class="empty">No approvals or disapprovals recorded yet.</p>
      <?php else: ?>
        <?php foreach ($processedRequests as $req): ?>
          <article class="request-card">
            <div class="grid">
              <div>
                <div class="label">Request #</div>
                <div class="value"><?php echo htmlspecialchars((string)$req['id']); ?></div>
              </div>
              <div>
                <div class="label">Status</div>
                <div class="value"><?php echo htmlspecialchars((string)($req['status'] ?? '')); ?></div>
              </div>
              <div>
                <div class="label">Requester</div>
                <div class="value"><?php echo htmlspecialchars((string)($req['requesters_name'] ?? '')); ?></div>
              </div>
            </div>
            <?php if (!empty($req['campus_director_signature'])): ?>
              <p class="label" style="margin-top:8px;">Signature on file: <?php echo htmlspecialchars((string)$req['campus_director_signature']); ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>

