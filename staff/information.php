<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'staff') {
  header('Location: ../main/index.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';

$userId = isset($user['id']) ? (string)$user['id'] : '';
$userEmail = isset($user['email']) ? (string)$user['email'] : '';
$sessionName = isset($user['name']) ? (string)$user['name'] : '';

$errors = [];
$success = '';

function fetch_current_user_staff(array $user, string $userId, string $userEmail, string $sessionName): array {
  // Prioritize by name field, then id, then email
  try {
    if ($sessionName !== '') {
      // Try to fetch by name first (with staff role filter)
      $rows = supabase_request('GET', 'users', null, ['select' => '*', 'name' => 'eq.' . $sessionName, 'user_type' => 'ilike.staff', 'limit' => 1]);
      if (!is_array($rows) || count($rows) === 0) {
        // Fallback without role filter
        $rows = supabase_request('GET', 'users', null, ['select' => '*', 'name' => 'eq.' . $sessionName, 'limit' => 1]);
      }
    } elseif ($userId !== '') {
      $rows = supabase_request('GET', 'users', null, ['select' => '*', 'id' => 'eq.' . $userId, 'limit' => 1]);
    } elseif ($userEmail !== '') {
      $rows = supabase_request('GET', 'users', null, ['select' => '*', 'email' => 'eq.' . $userEmail, 'limit' => 1]);
    } else {
      return $user;
    }
    if (is_array($rows) && count($rows) > 0 && is_array($rows[0])) {
      return $rows[0];
    }
  } catch (Throwable $e) {
  }
  return $user;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $update = [];
  if ($action === 'profile') {
    $update['name'] = trim((string)($_POST['name'] ?? ''));
    $update['contact_number'] = trim((string)($_POST['contact_number'] ?? ''));
    $update['birthday'] = (string)($_POST['birthday'] ?? '');
    $update['address'] = trim((string)($_POST['address'] ?? ''));
    $update['area_of_work'] = trim((string)($_POST['area_of_work'] ?? ''));
    // Handle image uploads
    $uploadsDir = realpath(__DIR__ . '/../uploads');
    if ($uploadsDir === false) { $uploadsDir = __DIR__ . '/../uploads'; }
    // Ensure directory exists
    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

    $allowedExt = ['jpg','jpeg','png','gif','webp'];
    $maxBytes = 5 * 1024 * 1024; // 5MB

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
      // Return web path relative to project root
      return 'uploads/' . $unique;
    };

    $uploadedProfile = $processUpload('profile_image_file');
    $uploadedSignature = $processUpload('signature_image_file');
    if ($uploadedProfile !== null) { $update['profile_image'] = $uploadedProfile; }
    if ($uploadedSignature !== null) { $update['signature_image'] = $uploadedSignature; }

    if ($update['name'] === '') { $errors[] = 'Name is required.'; }

    if (!$errors) {
      try {
        $query = [];
        if ($userId !== '') { $query['id'] = 'eq.' . $userId; }
        elseif ($userEmail !== '') { $query['email'] = 'eq.' . $userEmail; }
        elseif ($sessionName !== '') { $query['name'] = 'eq.' . $sessionName; $query['user_type'] = 'ilike.staff'; }
        else { throw new RuntimeException('Missing user identifier'); }

        $patched = supabase_request('PATCH', 'users', $update, $query);
        if (!is_array($patched) || count($patched) === 0) {
          // If no row updated, insert a new one so info will persist next time
          $row = $update;
          if (!isset($row['name']) || $row['name'] === '') { $row['name'] = $sessionName; }
          if (!isset($row['user_type'])) { $row['user_type'] = 'staff'; }
          if ($userEmail !== '') { $row['email'] = $userEmail; }
          supabase_insert('users', $row);
        }

        foreach (['name','contact_number','birthday','address','area_of_work','signature_image'] as $k) {
          if (isset($update[$k])) { $_SESSION['user'][$k] = $update[$k]; }
        }
        if (isset($update['profile_image']) && $update['profile_image'] !== '') {
          $_SESSION['user']['avatar'] = $update['profile_image'];
        }
        $user = $_SESSION['user'];
        $success = 'Profile updated successfully.';
      } catch (Throwable $e) {
        $errors[] = 'Failed to update profile.';
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
      try {
        $query = [];
        if ($userId !== '') { $query['id'] = 'eq.' . $userId; }
        elseif ($userEmail !== '') { $query['email'] = 'eq.' . $userEmail; }
        elseif ($sessionName !== '') { $query['name'] = 'eq.' . $sessionName; $query['user_type'] = 'ilike.staff'; }
        else { throw new RuntimeException('Missing user identifier'); }

        $patched = supabase_request('PATCH', 'users', ['password' => $newPassword], $query);
        if (!is_array($patched) || count($patched) === 0) {
          $row = [
            'name' => $sessionName,
            'user_type' => 'staff',
            'password' => $newPassword,
          ];
          if ($userEmail !== '') { $row['email'] = $userEmail; }
          supabase_insert('users', $row);
        }
        $success = 'Password updated successfully.';
      } catch (Throwable $e) {
        $errors[] = 'Failed to update password.';
      }
    }
  }
}

$dbUser = fetch_current_user_staff($user, $userId, $userEmail, $sessionName);

$displayName = (string)($dbUser['name'] ?? $user['name'] ?? '');
if ($displayName !== '' && isset($dbUser['name']) && $dbUser['name'] !== '') {
  $_SESSION['user']['name'] = $displayName; // Keep session in sync
}
$profileImage = (string)($dbUser['profile_image'] ?? $user['avatar'] ?? '');
$signatureImage = (string)($dbUser['signature_image'] ?? '');
$contactNumber = (string)($dbUser['contact_number'] ?? '');
$birthday = (string)($dbUser['birthday'] ?? '');
$address = (string)($dbUser['address'] ?? '');
$areaOfWork = (string)($dbUser['area_of_work'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Information</title>
<style>
  :root{
    --maroon-900: #3f0710;
    --maroon-800: #5a0f1b;
    --maroon-700: #7a1b2a;
    --maroon-600: #8b1f2f;
    --maroon-500: #a42b43;
    --muted: #fbf6f6;
    --card: #ffffff;
    --text: #111827;
    --muted-text: #6b7280;
    --success: #0b6b74;
    --danger: #b91c1c;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:Inter, -apple-system, system-ui,'Segoe UI',Roboto,Arial;background:linear-gradient(180deg,var(--muted),#fff 60%);color:var(--text);-webkit-font-smoothing:antialiased}
  .topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:linear-gradient(90deg,var(--maroon-800),var(--maroon-700));color:#fff;position:sticky;top:0;z-index:40;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
  .brand{font-weight:800;letter-spacing:0.4px;font-size:18px}
  .profile{display:flex;align-items:center;gap:12px}
  .avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;background:#fff3f4;border:2px solid rgba(255,255,255,0.12)}
  .name{font-weight:700;color:#fff}
  .container{max-width:1100px;margin:28px auto;padding:0 18px}
  .actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}
  .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;border:0;background:var(--maroon-700);color:#fff;font-weight:700;cursor:pointer;transition:transform .12s ease,background .12s ease}
  .btn.secondary{background:transparent;border:1px solid rgba(15,23,42,0.06);color:var(--maroon-700);font-weight:700}
  .btn:hover{transform:translateY(-3px);background:var(--maroon-800)}
  @media(max-width:820px){.actions{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:480px){.actions{grid-template-columns:1fr}}

  .card{background:var(--card);border-radius:14px;padding:18px;border:1px solid rgba(15,23,42,0.04);box-shadow:0 8px 20px rgba(16,24,40,0.04)}
  .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
  .field{display:flex;flex-direction:column;gap:8px}
  label{font-weight:700;color:var(--maroon-900)}
  input,textarea,select{padding:10px 12px;border-radius:10px;border:1px solid rgba(15,23,42,0.06);font:inherit}
  textarea{min-height:110px}
  .row{display:flex;gap:12px;align-items:center}
  .notice{padding:10px 12px;border-radius:8px}
  .error{background:#fff1f2;color:var(--danger);border:1px solid #ffd5da}
  .success{background:#ecfeff;color:var(--success);border:1px solid #cffafe}
  @media(max-width:640px){.grid{grid-template-columns:1fr}}
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
      <a class="btn" href="/maintenance/staff/pendingtask.php">Pending Task</a>
      <a class="btn" href="/maintenence/staff/accomplishment.php">Accomplishments</a>
      <button class="btn" type="button">Submit Work Leave/Absence</button>
    </section>

    <?php if ($errors): ?>
      <div class="notice error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php elseif ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <section class="card" aria-label="User information">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="profile">
        <div class="grid">
          <div class="field">
            <label for="name">Full Name</label>
            <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($displayName); ?>" required>
          </div>
          <div class="field">
            <label for="contact_number">Contact Number</label>
            <input id="contact_number" name="contact_number" type="text" value="<?php echo htmlspecialchars($contactNumber); ?>">
          </div>
          <div class="field">
            <label for="birthday">Birthday</label>
            <input id="birthday" name="birthday" type="date" value="<?php echo htmlspecialchars($birthday); ?>">
          </div>
          <div class="field">
            <label for="area_of_work">Area of Work</label>
            <input id="area_of_work" name="area_of_work" type="text" value="<?php echo htmlspecialchars($areaOfWork); ?>">
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label for="address">Address</label>
            <textarea id="address" name="address"><?php echo htmlspecialchars($address); ?></textarea>
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label>Profile Image</label>
            <?php if ($profileImage !== ''): ?>
              <div class="row"><img src="<?php echo htmlspecialchars('../' . $profileImage); ?>" alt="Profile" style="height:60px;border-radius:6px;border:1px solid #eee;"></div>
            <?php endif; ?>
            <input id="profile_image_file" name="profile_image_file" type="file" accept="image/*">
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label>Signature Image</label>
            <?php if ($signatureImage !== ''): ?>
              <div class="row"><img src="<?php echo htmlspecialchars('../' . $signatureImage); ?>" alt="Signature" style="height:60px;border-radius:6px;border:1px solid #eee;"></div>
            <?php endif; ?>
            <input id="signature_image_file" name="signature_image_file" type="file" accept="image/*">
          </div>
        </div>
        <div class="row" style="margin-top: 16px;">
          <button class="btn" type="submit">Save Changes</button>
        </div>
      </form>
    </section>

    <section class="card" style="margin-top:16px;" aria-label="Reset password">
      <form method="post">
        <input type="hidden" name="action" value="password">
        <div class="grid">
          <div class="field">
            <label for="new_password">New Password</label>
            <input id="new_password" name="new_password" type="password" required>
          </div>
          <div class="field">
            <label for="confirm_password">Confirm Password</label>
            <input id="confirm_password" name="confirm_password" type="password" required>
          </div>
        </div>
        <div class="row" style="margin-top: 16px;">
          <button class="btn" type="submit">Update Password</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>


