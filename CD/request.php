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
$departmentDefault = (string)($user['department'] ?? 'Campus Director');
$signatureDefault = (string)($user['signature_image'] ?? '');

function refresh_cd_profile(array &$user): array {
  try {
    $query = ['select' => '*', 'limit' => 1];
    if (isset($user['id']) && $user['id']) {
      $query['id'] = 'eq.' . (string)$user['id'];
    } elseif (isset($user['email']) && $user['email'] !== '') {
      $query['email'] = 'eq.' . (string)$user['email'];
      $query['user_type'] = 'ilike.campus_director';
    } else {
      $query = null;
    }
    if ($query !== null) {
      $rows = supabase_request('GET', 'users', null, $query);
      if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
        foreach (['name','department','signature_image','profile_image'] as $key) {
          if (!empty($rows[0][$key])) {
            $user[$key === 'profile_image' ? 'avatar' : $key] = $rows[0][$key];
          }
        }
      }
    }
  } catch (Throwable $e) {
  }
  $_SESSION['user'] = $user;
  return $user;
}

$user = refresh_cd_profile($user);
$displayName = (string)($user['name'] ?? $displayName);
$departmentDefault = (string)($user['department'] ?? $departmentDefault);
$signatureDefault = (string)($user['signature_image'] ?? $signatureDefault);

$errors = [];
$success = '';

function resolve_cd_user_id(array $user, string $displayName): ?int {
  try {
    if (isset($user['id']) && $user['id']) {
      return (int)$user['id'];
    }
    $query = null;
    if (isset($user['email']) && $user['email'] !== '') {
      $query = ['select' => 'id', 'limit' => 1, 'email' => 'eq.' . (string)$user['email']];
    } elseif ($displayName !== '') {
      $query = ['select' => 'id', 'limit' => 1, 'name' => 'eq.' . $displayName];
    }
    if ($query !== null) {
      $rows = supabase_request('GET', 'users', null, $query);
      if (is_array($rows) && isset($rows[0]['id'])) {
        $_SESSION['user']['id'] = (int)$rows[0]['id'];
        return (int)$rows[0]['id'];
      }
    }
    $insert = [
      'user_type' => 'campus_director',
      'name' => $displayName !== '' ? $displayName : 'Campus Director',
    ];
    if (isset($user['email']) && $user['email'] !== '') {
      $insert['email'] = (string)$user['email'];
    }
    $created = supabase_insert('users', $insert);
    if (isset($created['id'])) {
      $_SESSION['user']['id'] = (int)$created['id'];
      return (int)$created['id'];
    }
  } catch (Throwable $e) {
  }
  return null;
}

function ensure_uploads_dir(): string {
  $dir = realpath(__DIR__ . '/../uploads');
  if ($dir === false) { $dir = __DIR__ . '/../uploads'; }
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
  return $dir;
}

function process_upload(string $field): ?string {
  if (!isset($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  $file = $_FILES[$field];
  if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    return null;
  }
  $tmp = (string)($file['tmp_name'] ?? '');
  $name = (string)($file['name'] ?? '');
  $size = (int)($file['size'] ?? 0);
  if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0) {
    return null;
  }
  $allowed = ['jpg','jpeg','png','gif','webp'];
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowed, true)) {
    $ext = 'jpg';
  }
  $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'upload';
  $filename = $safeBase . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
  $dest = rtrim(ensure_uploads_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
  if (!@move_uploaded_file($tmp, $dest)) {
    return null;
  }
  return 'uploads/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $typesSelected = (array)($_POST['type_of_request'] ?? []);
  $typeOther = trim((string)($_POST['type_other'] ?? ''));
  $description = trim((string)($_POST['description_of_work'] ?? ''));
  $location = trim((string)($_POST['location'] ?? ''));
  $timeDuration = trim((string)($_POST['time_duration'] ?? ''));
  $numPersonnel = (string)($_POST['no_of_personnel_needed'] ?? '');
  $dateRequested = (string)($_POST['date_requested'] ?? '');

  if ($description === '') {
    $errors[] = 'Description of work is required.';
  }

  $types = $typesSelected;
  if ($typeOther !== '') {
    $types[] = $typeOther;
  }
  $typeOfRequest = implode(', ', array_filter(array_map('trim', $types)));

  $imageOfWork = process_upload('image_of_work');
$signaturePath = $signatureDefault !== '' ? $signatureDefault : null;

if ($signaturePath === null) {
  $errors[] = 'Upload your signature under My Information before submitting requests.';
}

  if (!$errors) {
    try {
      $userId = resolve_cd_user_id($user, $displayName);
      if ($userId === null) {
        throw new RuntimeException('Unable to resolve campus director account.');
      }
      $payload = [
        'user_id' => $userId,
        'requesters_name' => $displayName,
        'department' => $departmentDefault !== '' ? $departmentDefault : 'Campus Director',
        'type_of_request' => $typeOfRequest,
        'description_of_work' => $description,
        'location' => $location,
        'time_duration' => $timeDuration,
        'no_of_personnel_needed' => $numPersonnel === '' ? null : (int)$numPersonnel,
        'status' => 'Pending',
        'signature_of_requester' => $signaturePath,
      ];
      if ($dateRequested !== '') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRequested)) {
          $payload['date_requested'] = $dateRequested . 'T00:00:00Z';
        } else {
          $payload['date_requested'] = $dateRequested;
        }
      }
      if ($imageOfWork !== null) {
        $payload['image_of_work'] = $imageOfWork;
      }
      supabase_insert('work_request', $payload);
      $success = 'Work request submitted. MESO will review this in their Work Request panel.';
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
  <title>Campus Director Request</title>
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
    body { margin: 0; font-family: "Segoe UI", Roboto, Arial, sans-serif; background: #fff; color: var(--text); }
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      background: var(--offwhite);
    }
    .brand { font-weight: 700; color: var(--maroon-700); }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 10px 16px;
      border-radius: 10px;
      border: 1px solid var(--maroon-600);
      background: #fff;
      color: var(--maroon-700);
      text-decoration: none;
      font-weight: 600;
    }
    .btn:hover { background: var(--maroon-600); color: #fff; }
    main {
      max-width: 1100px;
      margin: 0 auto;
      padding: 24px 16px 40px;
    }
    .card {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 20px;
      background: #fff;
    }
    .card + .card { margin-top: 16px; }
    .section-title {
      margin: 16px 0 10px;
      padding-left: 10px;
      border-left: 3px solid var(--maroon-400);
      color: var(--maroon-700);
      font-weight: 700;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
    }
    .field { display: flex; flex-direction: column; gap: 6px; }
    label { font-weight: 600; color: var(--maroon-700); }
    input, textarea {
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid var(--border);
      font: inherit;
    }
    textarea { min-height: 110px; resize: vertical; }
    .notice {
      padding: 12px 16px;
      border-radius: 10px;
      margin-bottom: 16px;
      font-size: 14px;
    }
    .success { background: #ecfdf5; border: 1px solid #34d399; color: #065f46; }
    .error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
    .checklist {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 10px;
    }
    .check-item {
      display: flex;
      align-items: center;
      gap: 8px;
      border: 1px dashed var(--border);
      padding: 10px 12px;
      border-radius: 8px;
      background: #fafafa;
    }
    .help { color: var(--muted); font-size: 12px; }
  </style>
</head>
<body>
  <header>
    <div class="brand">Campus Director Request</div>
    <div style="display:flex; gap:10px;">
      <a class="btn" href="dashboard.php">Back to Dashboard</a>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main>
    <?php if ($errors): ?>
      <div class="notice error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php elseif ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <section class="card">
      <h2 style="margin:0 0 12px;color:var(--maroon-700);">Work Request Form</h2>
      <p style="margin:0 0 20px;color:var(--muted);">Requests submitted here will appear in the MESO Work Request queue for approval.</p>
      <form method="post" enctype="multipart/form-data">
        <div class="grid">
          <div class="field">
            <label>Requested By</label>
            <input type="text" value="<?php echo htmlspecialchars($displayName); ?>" readonly>
            <div class="help">Automatically submitted as the Campus Director.</div>
          </div>
          <div class="field">
            <label for="date_requested">Date</label>
            <input id="date_requested" name="date_requested" type="date" value="<?php echo htmlspecialchars($_POST['date_requested'] ?? ''); ?>">
          </div>
        </div>

        <h3 class="section-title">Request Details</h3>
        <div class="field" style="margin-bottom:14px;">
          <label>Type of Request</label>
          <div class="checklist">
            <?php
              $options = ['Electrical','Plumbing','Repair and Maintenance'];
              $selected = isset($_POST['type_of_request']) ? (array)$_POST['type_of_request'] : [];
              foreach ($options as $opt):
            ?>
              <label class="check-item">
                <input type="checkbox" name="type_of_request[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo in_array($opt, $selected, true) ? 'checked' : ''; ?>>
                <?php echo htmlspecialchars($opt); ?>
              </label>
            <?php endforeach; ?>
            <label class="check-item" style="grid-column: 1 / -1;">
              <span>Others</span>
              <input name="type_other" type="text" value="<?php echo htmlspecialchars($_POST['type_other'] ?? ''); ?>" style="flex:1;">
            </label>
          </div>
        </div>

        <div class="grid">
          <div class="field" style="grid-column: 1 / -1;">
            <label for="description_of_work">Description of Work</label>
            <textarea id="description_of_work" name="description_of_work" required><?php echo htmlspecialchars($_POST['description_of_work'] ?? ''); ?></textarea>
          </div>
          <div class="field">
            <label for="location">Location</label>
            <input id="location" name="location" type="text" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
          </div>
          <div class="field">
            <label for="time_duration">Time Duration (e.g. 3 days)</label>
            <input id="time_duration" name="time_duration" type="text" value="<?php echo htmlspecialchars($_POST['time_duration'] ?? ''); ?>">
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
          </div>
          <div class="field">
            <label>Signature on file</label>
            <?php if ($signatureDefault !== ''): ?>
              <div class="help">Using your saved signature automatically.</div>
              <div style="font-size:13px;color:var(--muted);"><?php echo htmlspecialchars($signatureDefault); ?></div>
            <?php else: ?>
              <div class="help" style="color:#b91c1c;">No signature saved. Please upload one in My Information.</div>
            <?php endif; ?>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:18px;">
          <button class="btn" type="submit">Submit Request</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>

