<?php
declare(strict_types=1);

session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'campus_director') {
  header('Location: loginCD.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';

$userId = isset($user['id']) ? (string)$user['id'] : '';
$userEmail = isset($user['email']) ? (string)$user['email'] : '';
$errors = [];
$success = '';

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

function process_upload(string $field): ?string {
  if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
    return null;
  }
  $file = $_FILES[$field];
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
  $safeBase = preg_replace('/[^a-z0-9_-]/i', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'upload';
  $filename = $safeBase . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
  $dest = rtrim(uploads_directory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
  if (!@move_uploaded_file($tmp, $dest)) {
    return null;
  }
  return 'uploads/' . $filename;
}

function fetch_cd_profile(string $userId, string $userEmail, array $fallback): array {
  try {
    $query = ['select' => '*', 'limit' => 1];
    if ($userId !== '') {
      $query['id'] = 'eq.' . $userId;
    } elseif ($userEmail !== '') {
      $query['email'] = 'eq.' . $userEmail;
      $query['user_type'] = 'ilike.campus_director';
    } else {
      return $fallback;
    }
    $rows = supabase_request('GET', 'users', null, $query);
    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
      return $rows[0];
    }
  } catch (Throwable $e) {
  }
  return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'profile') {
    $update = [
      'name' => trim((string)($_POST['name'] ?? '')),
      'department' => trim((string)($_POST['department'] ?? '')),
      'contact_number' => trim((string)($_POST['contact_number'] ?? '')),
      'birthday' => (string)($_POST['birthday'] ?? ''),
      'address' => trim((string)($_POST['address'] ?? '')),
    ];
    if ($update['name'] === '') {
      $errors[] = 'Full name is required.';
    }
    $profileImage = process_upload('profile_image_file');
    if ($profileImage !== null) {
      $update['profile_image'] = $profileImage;
    }
    $signatureImage = process_upload('signature_image_file');
    if ($signatureImage !== null) {
      $update['signature_image'] = $signatureImage;
    }
    if (!$errors) {
      $query = [];
      if ($userId !== '') {
        $query['id'] = 'eq.' . $userId;
      } elseif ($userEmail !== '') {
        $query['email'] = 'eq.' . $userEmail;
        $query['user_type'] = 'ilike.campus_director';
      } else {
        $errors[] = 'Missing user identifier.';
      }
      if (!$errors) {
        try {
          $patched = supabase_request('PATCH', 'users', $update, $query);
          if (!is_array($patched) || count($patched) === 0) {
            $update['user_type'] = 'campus_director';
            supabase_insert('users', $update);
          }
          foreach (['name','department','contact_number','birthday','address','signature_image'] as $key) {
            if (isset($update[$key])) {
              $_SESSION['user'][$key] = $update[$key];
            }
          }
          if (isset($update['profile_image'])) {
            $_SESSION['user']['avatar'] = $update['profile_image'];
          }
          $user = $_SESSION['user'];
          $success = 'Profile updated.';
        } catch (Throwable $e) {
          $errors[] = 'Unable to save profile.';
        }
      }
    }
  } elseif ($action === 'password') {
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if ($newPassword === '' || $confirmPassword === '') {
      $errors[] = 'Password and confirmation are required.';
    } elseif ($newPassword !== $confirmPassword) {
      $errors[] = 'Passwords do not match.';
    } else {
      $query = [];
      if ($userId !== '') {
        $query['id'] = 'eq.' . $userId;
      } elseif ($userEmail !== '') {
        $query['email'] = 'eq.' . $userEmail;
        $query['user_type'] = 'ilike.campus_director';
      } else {
        $errors[] = 'Missing user identifier.';
      }
      if (!$errors) {
        try {
          supabase_request('PATCH', 'users', ['password' => $newPassword], $query);
          $success = 'Password updated.';
        } catch (Throwable $e) {
          $errors[] = 'Unable to update password.';
        }
      }
    }
  }
}

$dbUser = fetch_cd_profile($userId, $userEmail, $user);
$displayName = (string)($dbUser['name'] ?? $user['name'] ?? '');
if ($displayName !== '') {
  $_SESSION['user']['name'] = $displayName;
}
$department = (string)($dbUser['department'] ?? '');
$email = (string)($dbUser['email'] ?? $userEmail);
$contact = (string)($dbUser['contact_number'] ?? '');
$birthday = (string)($dbUser['birthday'] ?? '');
$address = (string)($dbUser['address'] ?? '');
$profileImage = (string)($dbUser['profile_image'] ?? $user['avatar'] ?? '');
$signatureImage = (string)($dbUser['signature_image'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Campus Director Information</title>
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
    .actions {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 24px;
    }
    .card {
      background: #fff;
      border-radius: 18px;
      padding: 20px;
      border: 1px solid var(--gray-200);
      margin-bottom: 24px;
    }
    .card h2 { margin-top: 0; color: var(--maroon-700); }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
    }
    label { font-weight: 600; color: var(--maroon-700); margin-bottom: 6px; display: block; }
    input, textarea {
      width: 100%;
      padding: 12px;
      border-radius: 10px;
      border: 1px solid var(--gray-200);
      font: inherit;
    }
    textarea { min-height: 100px; resize: vertical; }
    .notice {
      padding: 10px 14px;
      border-radius: 10px;
      margin-bottom: 12px;
      font-size: 14px;
    }
    .success { background: #ecfdf5; border: 1px solid #34d399; color: #065f46; }
    .error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
    .preview {
      max-width: 160px;
      border-radius: 12px;
      border: 1px solid var(--gray-200);
    }
  </style>
</head>
<body>
  <header>
    <div class="brand">My information</div>
    <a class="btn" href="dashboard.php">Back to dashboard</a>
  </header>
  <main>
    <section class="actions">
      <a class="btn" href="reports.php">Reports</a>
      <a class="btn" href="workrequest.php">Work Requests</a>
      <a class="btn" href="approvallist.php">Approval List</a>
      <a class="btn" href="mesoLA.php">Leave & Absence</a>
    </section>

    <?php if ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
      <div class="notice error"><?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>

    <section class="card">
      <h2>Profile</h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="profile">
        <div class="grid">
          <div>
            <label for="name">Full name</label>
            <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($displayName); ?>" required>
          </div>
          <div>
            <label for="department">Department</label>
            <input id="department" name="department" type="text" value="<?php echo htmlspecialchars($department); ?>">
          </div>
          <div>
            <label for="email">Email</label>
            <input id="email" type="email" value="<?php echo htmlspecialchars($email); ?>" readonly>
          </div>
          <div>
            <label for="contact_number">Contact number</label>
            <input id="contact_number" name="contact_number" type="text" value="<?php echo htmlspecialchars($contact); ?>">
          </div>
          <div>
            <label for="birthday">Birthday</label>
            <input id="birthday" name="birthday" type="date" value="<?php echo htmlspecialchars($birthday); ?>">
          </div>
          <div style="grid-column: 1 / -1;">
            <label for="address">Address</label>
            <textarea id="address" name="address"><?php echo htmlspecialchars($address); ?></textarea>
          </div>
          <div style="grid-column: 1 / -1;">
            <label>Profile photo</label>
            <?php if ($profileImage !== ''): ?>
              <img class="preview" src="<?php echo htmlspecialchars('../' . $profileImage); ?>" alt="Profile">
            <?php endif; ?>
            <input type="file" name="profile_image_file" accept="image/*">
          </div>
          <div style="grid-column: 1 / -1;">
            <label>Signature image</label>
            <?php if ($signatureImage !== ''): ?>
              <img class="preview" src="<?php echo htmlspecialchars('../' . $signatureImage); ?>" alt="Signature">
            <?php endif; ?>
            <input type="file" name="signature_image_file" accept="image/*">
          </div>
        </div>
        <button class="btn" type="submit" style="margin-top:16px;">Save changes</button>
      </form>
    </section>

    <section class="card">
      <h2>Update password</h2>
      <form method="post">
        <input type="hidden" name="action" value="password">
        <div class="grid">
          <div>
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" required>
          </div>
          <div>
            <label for="confirm_password">Confirm password</label>
            <input id="confirm_password" name="confirm_password" type="password" required>
          </div>
        </div>
        <button class="btn" type="submit" style="margin-top:16px;">Update password</button>
      </form>
    </section>
  </main>
</body>
</html>

