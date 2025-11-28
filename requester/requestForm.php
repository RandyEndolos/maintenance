<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'requester') {
  header('Location: ../main/index.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';

// Resolve display name from DB - prioritize by name field
$topbarName = (string)($user['name'] ?? 'Requester');
// Prefill department and signature from session, but override with DB if available
$departmentDefault = (string)($user['department'] ?? '');
$signatureDefault = (string)($user['signature_image'] ?? '');
try {
  $query = ['select' => '*', 'limit' => 1];
  $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
  
  if ($userName !== '') {
    // Try to fetch by name first (with requester role filter)
    $query['name'] = 'eq.' . $userName;
    $query['user_type'] = 'ilike.requester';
  } elseif (isset($user['id']) && $user['id'] !== null && $user['id'] !== '') {
    $query = ['select' => '*', 'limit' => 1];
    $query['id'] = 'eq.' . (string)$user['id'];
  } else {
    $query = null;
  }
  
  if ($query !== null) {
    $rows = supabase_request('GET', 'users', null, $query);
    if (is_array($rows) && count($rows) > 0) {
      if (isset($rows[0]['name']) && trim((string)$rows[0]['name']) !== '') {
        $topbarName = (string)$rows[0]['name'];
        $_SESSION['user']['name'] = $topbarName;
      }
      if (isset($rows[0]['department']) && trim((string)$rows[0]['department']) !== '') {
        $departmentDefault = (string)$rows[0]['department'];
        $_SESSION['user']['department'] = $departmentDefault;
      }
      if (isset($rows[0]['signature_image']) && trim((string)$rows[0]['signature_image']) !== '') {
        $signatureDefault = (string)$rows[0]['signature_image'];
        $_SESSION['user']['signature_image'] = $signatureDefault;
      }
    }
  }
} catch (Throwable $e) {
  // Fall back to session name
}

$errors = [];
$success = '';

// Defaults from session for convenience
$requestersNameDefault = (string)($user['name'] ?? '');
$departmentDefault = (string)($user['department'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Resolve user id to satisfy work_request.user_id FK/NOT NULL - prioritize by name
  $resolveUserId = function(array $user, string $requestersName): ?int {
    try {
      // Prefer existing session id
      if (isset($user['id']) && $user['id'] !== '' && $user['id'] !== null) {
        return (int)$user['id'];
      }
      // Try fetch by name first (with requester role filter)
      if ($requestersName !== '') {
        $rows = supabase_request('GET', 'users', null, ['select' => '*', 'name' => 'eq.' . $requestersName, 'user_type' => 'ilike.requester', 'limit' => 1]);
        if (is_array($rows) && count($rows) > 0 && isset($rows[0]['id'])) {
          $_SESSION['user']['id'] = (int)$rows[0]['id'];
          return (int)$rows[0]['id'];
        }
      }
      // Fallback to email only if name lookup failed
      $userEmail = isset($user['email']) ? (string)$user['email'] : '';
      if ($userEmail !== '') {
        $rows = supabase_request('GET', 'users', null, ['select' => '*', 'email' => 'eq.' . $userEmail, 'limit' => 1]);
        if (is_array($rows) && count($rows) > 0 && isset($rows[0]['id'])) {
          $_SESSION['user']['id'] = (int)$rows[0]['id'];
          return (int)$rows[0]['id'];
        }
      }
      // Create minimal user row to get an id
      $newUser = [
        'user_type' => 'requester',
        'name' => $requestersName !== '' ? $requestersName : 'Requester',
      ];
      if ($userEmail !== '') { $newUser['email'] = $userEmail; }
      $inserted = supabase_insert('users', $newUser);
      if (is_array($inserted) && isset($inserted['id'])) {
        $_SESSION['user']['id'] = (int)$inserted['id'];
        return (int)$inserted['id'];
      }
    } catch (Throwable $e) {
      // fall through and return null
    }
    return null;
  };

  $requestersName = trim((string)($_POST['requesters_name'] ?? $requestersNameDefault));
  $department = trim((string)($_POST['department'] ?? ''));
  $dateRequested = (string)($_POST['date_requested'] ?? '');
  $typeOptions = (array)($_POST['type_of_request'] ?? []);
  $typeOther = trim((string)($_POST['type_other'] ?? ''));
  $description = trim((string)($_POST['description_of_work'] ?? ''));
  $location = trim((string)($_POST['location'] ?? ''));
  $timeDuration = trim((string)($_POST['time_duration'] ?? ''));
  $numPersonnel = (string)($_POST['no_of_personnel_needed'] ?? '');

  // Basic validation
  if ($requestersName === '') { $errors[] = 'Requester name is required.'; }
  if ($department === '') { $errors[] = 'Department is required.'; }
  if ($description === '') { $errors[] = 'Description of work is required.'; }

  // Build type_of_request combined string
  $types = $typeOptions;
  if ($typeOther !== '') { $types[] = $typeOther; }
  $typeOfRequest = implode(', ', array_map('trim', $types));

  // Handle optional uploads
  $uploadsDir = realpath(__DIR__ . '/../uploads');
  if ($uploadsDir === false) { $uploadsDir = __DIR__ . '/../uploads'; }
  if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

  $allowedExt = ['jpg','jpeg','png','gif','webp'];
  $maxBytes = 8 * 1024 * 1024; // 8MB

  $processUpload = function(string $field) use ($uploadsDir, $allowedExt, $maxBytes): ?string {
    if (!isset($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
      return null;
    }
    $err = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_OK);
    if ($err !== UPLOAD_ERR_OK) { return null; }
    $tmp = (string)($_FILES[$field]['tmp_name'] ?? '');
    $name = (string)($_FILES[$field]['name'] ?? '');
    $size = (int)($_FILES[$field]['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) { return null; }
    if ($size <= 0 || $size > $maxBytes) { return null; }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) { $ext = 'jpg'; }
    $destName = pathinfo($name, PATHINFO_FILENAME);
    $destName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $destName) ?: 'upload';
    $unique = $destName . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
    $destPath = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $unique;
    if (!@move_uploaded_file($tmp, $destPath)) { return null; }
    return 'uploads/' . $unique;
  };

  $imageOfWork = $processUpload('image_of_work');
  $signatureOfRequester = $processUpload('signature_of_requester');
  if ($signatureOfRequester === null && $signatureDefault !== '') {
    $signatureOfRequester = $signatureDefault; // use saved signature if no new upload
  }

  if (!$errors) {
    try {
      $resolvedUserId = $resolveUserId($user, $requestersName);
      if ($resolvedUserId === null) { throw new RuntimeException('Unable to resolve user id'); }
      $row = [
        'user_id' => $resolvedUserId,
        'requesters_name' => $requestersName,
        'department' => $department,
        'type_of_request' => $typeOfRequest,
        'description_of_work' => $description,
        'location' => $location,
        'time_duration' => $timeDuration,
        'no_of_personnel_needed' => $numPersonnel === '' ? null : (int)$numPersonnel,
        'status' => 'Pending',
      ];
      if ($dateRequested !== '') {
        // Accept yyyy-mm-dd and add time, or pass-through if full ISO
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRequested)) {
          $row['date_requested'] = $dateRequested . 'T00:00:00Z';
        } else {
          $row['date_requested'] = $dateRequested;
        }
      }
      if ($imageOfWork !== null) { $row['image_of_work'] = $imageOfWork; }
      if ($signatureOfRequester !== null) { $row['signature_of_requester'] = $signatureOfRequester; }

      supabase_insert('work_request', $row);
      $success = 'Work request submitted. Status set to Pending.';
      // Clear POST values after success for UX
      $_POST = [];
    } catch (Throwable $e) {
      $errors[] = 'Failed to submit work request.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submit Work Request</title>
<style>
  :root {
    --maroon-700: #5a0f1b;
    --maroon-600: #7a1b2a;
    --maroon-400: #a42b43;
    --offwhite: #f9f6f7;
    --text: #222;
    --muted: #6b7280;
    --border: #e5e7eb;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #fff; color: var(--text); }
  .topbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #eee; background: var(--offwhite); }
  .brand { font-weight: 700; color: var(--maroon-700); }
  .profile { display: flex; align-items: center; gap: 10px; }
  .avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: #ddd; }
  .name { font-weight: 600; color: var(--maroon-700); }
  .container { max-width: 1100px; margin: 20px auto; padding: 0 16px; }
  .actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
  .btn { display: inline-block; text-decoration: none; text-align: center; padding: 14px 16px; border-radius: 10px; border: 1px solid #e5e5e5; background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .15s ease, border-color .15s ease, transform .1s ease; }
  .btn:hover { background: #fff7f8; border-color: var(--maroon-400); }
  .btn:active { transform: translateY(1px); }
  .page-title { margin: 0 0 6px; color: var(--maroon-700); font-size: 22px; font-weight: 700; }
  .subtext { margin: 0 0 16px; color: var(--muted); font-size: 14px; }
  .card { border: 1px solid var(--border); border-radius: 12px; padding: 18px; background: #fff; }
  .card + .card { margin-top: 16px; }
  .section-title { margin: 4px 0 12px; padding-left: 10px; border-left: 3px solid var(--maroon-400); color: var(--maroon-700); font-weight: 700; }
  .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: start; }
  .field { display: flex; flex-direction: column; gap: 6px; }
  label { font-weight: 600; color: var(--maroon-700); }
  input, textarea, select { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font: inherit; background: #fff; }
  input[readonly], input[disabled] { background: #f9fafb; color: #6b7280; }
  textarea { min-height: 100px; resize: vertical; }
  .row { display: flex; gap: 12px; align-items: center; }
  .checklist { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; padding: 8px 0; }
  .check-item { display: flex; gap: 8px; align-items: center; border: 1px dashed var(--border); padding: 10px 12px; border-radius: 8px; background: #fafafa; }
  .help { color: var(--muted); font-size: 12px; }
  .notice { padding: 10px 12px; border-radius: 8px; }
  .error { background: #fff1f2; color: #7a1b2a; border: 1px solid #ffd5da; }
  .success { background: #ecfeff; color: #0b6b74; border: 1px solid #cffafe; }
  .actions-bar { display: flex; justify-content: flex-end; gap: 12px; }
  @media (max-width: 640px) { .actions { grid-template-columns: 1fr; } .grid, .checklist { grid-template-columns: 1fr; } }
</style>
</head>
<body>
  <header class="topbar">
    <div class="brand">RCC Requester</div>
    <div class="profile">
      
      <div class="name"><?php echo htmlspecialchars($topbarName); ?></div>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main class="container">
    <section class="actions" aria-label="Requester actions">
      <a class="btn" href="information.php">Information</a>
      <a class="btn" href="dashboard.php">Back to Home</a>
      <button class="btn" type="button">Claim Work Request</button>
    </section>

    <?php if ($errors): ?>
      <div class="notice error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php elseif ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <section class="card" aria-label="Submit work request">
      <h2 class="page-title">Work Request Form</h2>
      <p class="subtext">Please complete all required fields. Attach a photo of the work if available.</p>
      <form method="post" enctype="multipart/form-data">
        <h3 class="section-title">Requester Information</h3>
        <div class="grid">
          <div class="field">
            <label for="requesters_name">Requester Name</label>
            <input id="requesters_name" name="requesters_name" type="text" value="<?php echo htmlspecialchars($_POST['requesters_name'] ?? $requestersNameDefault); ?>" required>
            <div class="help">Enter your full name</div>
          </div>
          <div class="field">
            <label for="department">Requesting Office/Department</label>
            <input id="department" name="department" type="text" value="<?php echo htmlspecialchars($_POST['department'] ?? $departmentDefault); ?>" required>
          </div>
          <div class="field">
            <label for="date_requested">Date</label>
            <input id="date_requested" name="date_requested" type="date" value="<?php echo htmlspecialchars($_POST['date_requested'] ?? ''); ?>">
          </div>
        </div>

        <h3 class="section-title">Request Details</h3>
        <div class="grid">
          <div class="field" style="grid-column: 1 / -1;">
            <label>Type of Request</label>
            <div class="checklist">
              <label class="check-item"><input type="checkbox" name="type_of_request[]" value="Electrical" <?php echo isset($_POST['type_of_request']) && in_array('Electrical', (array)$_POST['type_of_request'], true) ? 'checked' : ''; ?>> Electrical</label>
              <label class="check-item"><input type="checkbox" name="type_of_request[]" value="Plumbing" <?php echo isset($_POST['type_of_request']) && in_array('Plumbing', (array)$_POST['type_of_request'], true) ? 'checked' : ''; ?>> Plumbing</label>
              <label class="check-item"><input type="checkbox" name="type_of_request[]" value="Repair and Maintenance" <?php echo isset($_POST['type_of_request']) && in_array('Repair and Maintenance', (array)$_POST['type_of_request'], true) ? 'checked' : ''; ?>> Repair and Maintenance</label>
              <label class="check-item" style="grid-column: 1 / -1;">
                <span style="min-width:70px;">Others</span>
                <input name="type_other" type="text" value="<?php echo htmlspecialchars($_POST['type_other'] ?? ''); ?>" style="flex:1;">
              </label>
            </div>
          </div>

          <div class="field" style="grid-column: 1 / -1;">
            <label for="description_of_work">Description of Work</label>
            <textarea id="description_of_work" name="description_of_work" required><?php echo htmlspecialchars($_POST['description_of_work'] ?? ''); ?></textarea>
            <div class="help">Provide details to help us assess the request.</div>
          </div>

          <div class="field">
            <label for="location">Location</label>
            <input id="location" name="location" type="text" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
          </div>
          <div class="field">
            <label for="time_duration">Time Duration of Project</label>
            <input id="time_duration" name="time_duration" type="text" placeholder="e.g., 3 days" value="<?php echo htmlspecialchars($_POST['time_duration'] ?? ''); ?>">
            <div class="help">Use phrases like "3 days", "12 hours", or "1 week" so the system can monitor the deadline.</div>
          </div>

          <div class="field">
            <label for="no_of_personnel_needed">No. of Personnel Needed</label>
            <input id="no_of_personnel_needed" name="no_of_personnel_needed" type="number" min="0" value="<?php echo htmlspecialchars($_POST['no_of_personnel_needed'] ?? ''); ?>">
          </div>
        </div>

        <h3 class="section-title">Attachments</h3>
        <div class="grid">
          <div class="field">
            <label for="image_of_work">Image of Work (optional)</label>
            <input id="image_of_work" name="image_of_work" type="file" accept="image/*">
            <div class="help">Upload a clear photo if possible.</div>
          </div>
          <div class="field">
            <label for="signature_of_requester">Requested by (Signature)</label>
            <?php if ($signatureDefault !== ''): ?>
              <div class="row"><img src="<?php echo htmlspecialchars('../' . $signatureDefault); ?>" alt="Saved signature" style="height:60px;border-radius:6px;border:1px solid #eee;background:#fff;"></div>
              <div class="help">Using your saved signature by default. Upload to override for this request.</div>
            <?php else: ?>
              <div class="help">Upload your signature image for this request.</div>
            <?php endif; ?>
            <input id="signature_of_requester" name="signature_of_requester" type="file" accept="image/*">
          </div>
        </div>

        <div class="actions-bar" style="margin-top: 16px;">
          <button class="btn" type="submit">Submit Request</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>


