<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
  header('Location: ../main/index.php');
  exit;
}

// Set Supabase environment variables if not already set
if ((getenv('SUPABASE_URL') ?: '') === '') {
  putenv('SUPABASE_URL=https://kmrqgqodgwwseaotbsvt.supabase.co');
}
if ((getenv('SUPABASE_ANON_KEY') ?: '') === '') {
  putenv('SUPABASE_ANON_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImttcnFncW9kZ3d3c2Vhb3Ric3Z0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MTY1OTYsImV4cCI6MjA3NzI5MjU5Nn0._HFdvA-wDeMkU6TOij0IIxsbJCSnoOEtLP8yKFUDAnE');
}

require_once __DIR__ . '/../supabase_rest.php';

// Resolve user identifier
$userId = isset($user['id']) ? (string)$user['id'] : '';
$userEmail = isset($user['email']) ? (string)$user['email'] : '';
$userName = isset($user['name']) ? (string)$user['name'] : '';

$errors = [];
$success = '';

// Try to resolve identifier early - prioritize by name
if (($userId === '' || $userId === '0') && $userName !== '') {
  try {
    $rows = supabase_request('GET', 'users', null, ['select' => 'id,email', 'name' => 'eq.' . $userName, 'user_type' => 'ilike.admin', 'limit' => 1]);
    if (is_array($rows) && count($rows) > 0 && isset($rows[0]['id'])) {
      $userId = (string)$rows[0]['id'];
      $_SESSION['user']['id'] = (int)$rows[0]['id'];
      if (isset($rows[0]['email']) && $rows[0]['email'] !== '') {
        $userEmail = (string)$rows[0]['email'];
        $_SESSION['user']['email'] = $userEmail;
      }
    }
  } catch (Throwable $e) {
    // Silently continue - will try again in resolveIdentifier
  }
}
// Fallback to email only if name lookup failed
if (($userId === '' || $userId === '0') && $userEmail !== '' && $userEmail !== 'null') {
  try {
    $rows = supabase_request('GET', 'users', null, ['select' => 'id,email', 'email' => 'eq.' . $userEmail, 'user_type' => 'ilike.admin', 'limit' => 1]);
    if (is_array($rows) && count($rows) > 0 && isset($rows[0]['id'])) {
      $userId = (string)$rows[0]['id'];
      $_SESSION['user']['id'] = (int)$rows[0]['id'];
      if (isset($rows[0]['email']) && $rows[0]['email'] !== '') {
        $userEmail = (string)$rows[0]['email'];
        $_SESSION['user']['email'] = $userEmail;
      }
    }
  } catch (Throwable $e) {
    // Silently continue - will try again in resolveIdentifier
  }
}

function fetch_current_user(array $user, string $userId, string $userEmail): array {
  // Prioritize by name field, then id, then email
  try {
    $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
    
    if ($userName !== '') {
      // Try to fetch by name first (with admin role filter)
      $rows = supabase_request('GET', 'users', null, ['select' => '*', 'name' => 'eq.' . $userName, 'user_type' => 'ilike.admin', 'limit' => 1]);
      if (!is_array($rows) || count($rows) === 0) {
        // Fallback without role filter
        $rows = supabase_request('GET', 'users', null, ['select' => '*', 'name' => 'eq.' . $userName, 'limit' => 1]);
      }
    } elseif ($userId !== '') {
      $rows = supabase_request('GET', 'users', null, ['select' => '*', 'id' => 'eq.' . $userId, 'limit' => 1]);
    } elseif ($userEmail !== '') {
      $rows = supabase_request('GET', 'users', null, ['select' => '*', 'email' => 'eq.' . $userEmail, 'limit' => 1]);
    } else {
      return $user; // Nothing better we can do
    }
    if (is_array($rows) && count($rows) > 0 && is_array($rows[0])) {
      return $rows[0];
    }
  } catch (Throwable $e) {
    // Silently fall back to session user
  }
  return $user;
}

// Handle profile updates
// Resolve identifier helper: ensures we have id/email for the current logged-in admin
$resolveIdentifier = function(string &$userId, string &$userEmail, string $userName): void {
  if ($userId !== '' || $userEmail !== '') { return; }
  try {
    $rows = [];
    if ($userName !== '') {
      // Try by name first (admin role if present), fallback without role if not found
      $rows = supabase_request('GET', 'users', null, ['select' => '*', 'name' => 'eq.' . $userName, 'user_type' => 'ilike.admin', 'limit' => 1]);
      if (!is_array($rows) || count($rows) === 0) {
        $rows = supabase_request('GET', 'users', null, ['select' => '*', 'name' => 'eq.' . $userName, 'limit' => 1]);
      }
    }
    if (is_array($rows) && count($rows) > 0 && isset($rows[0]['id'])) {
      $userId = (string)$rows[0]['id'];
      $_SESSION['user']['id'] = (int)$rows[0]['id'];
      if (isset($rows[0]['email']) && $rows[0]['email'] !== '') {
        $userEmail = (string)$rows[0]['email'];
        $_SESSION['user']['email'] = $userEmail;
      }
    }
  } catch (Throwable $e) {
    // leave as-is
  }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $update = [];
  // Ensure we have an identifier (id or email); fallback resolve by name + role
  $resolveIdentifier($userId, $userEmail, $userName);
  if ($action === 'profile') {
    $update['name'] = trim((string)($_POST['name'] ?? ''));
    $update['email'] = trim((string)($_POST['email'] ?? ''));
    $update['birthday'] = (string)($_POST['birthday'] ?? '');
    $update['address'] = trim((string)($_POST['address'] ?? ''));
    // department/contact_number/area_of_work not used here
    // profile_image handled via file upload below

    // Optional signature upload handling
    $uploadsDir = realpath(__DIR__ . '/../uploads');
    if ($uploadsDir === false) { $uploadsDir = __DIR__ . '/../uploads'; }
    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }
    $allowedExt = ['jpg','jpeg','png','gif','webp'];
    $maxBytes = 8 * 1024 * 1024; // 8MB
    $signaturePath = '';
    $profileImagePath = '';

    // Handle profile image upload (optional)
    if (isset($_FILES['profile_image']) && (int)($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $err = (int)($_FILES['profile_image']['error'] ?? UPLOAD_ERR_OK);
      if ($err === UPLOAD_ERR_OK) {
        $tmp = (string)($_FILES['profile_image']['tmp_name'] ?? '');
        $name = (string)($_FILES['profile_image']['name'] ?? '');
        $size = (int)($_FILES['profile_image']['size'] ?? 0);
        if ($tmp !== '' && is_uploaded_file($tmp) && $size > 0 && $size <= $maxBytes) {
          $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
          if (!in_array($ext, $allowedExt, true)) { $ext = 'jpg'; }
          $destName = pathinfo($name, PATHINFO_FILENAME);
          $destName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $destName) ?: 'profile';
          $unique = $destName . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
          $destPath = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $unique;
          if (@move_uploaded_file($tmp, $destPath)) {
            $profileImagePath = 'uploads/' . $unique;
            $update['profile_image'] = $profileImagePath;
          }
        }
      }
    }
    if (isset($_FILES['signature_image']) && (int)($_FILES['signature_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $err = (int)($_FILES['signature_image']['error'] ?? UPLOAD_ERR_OK);
      if ($err === UPLOAD_ERR_OK) {
        $tmp = (string)($_FILES['signature_image']['tmp_name'] ?? '');
        $name = (string)($_FILES['signature_image']['name'] ?? '');
        $size = (int)($_FILES['signature_image']['size'] ?? 0);
        if ($tmp !== '' && is_uploaded_file($tmp) && $size > 0 && $size <= $maxBytes) {
          $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
          if (!in_array($ext, $allowedExt, true)) { $ext = 'jpg'; }
          $destName = pathinfo($name, PATHINFO_FILENAME);
          $destName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $destName) ?: 'signature';
          $unique = $destName . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
          $destPath = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $unique;
          if (@move_uploaded_file($tmp, $destPath)) {
            $signaturePath = 'uploads/' . $unique;
            $update['signature_image'] = $signaturePath;
          }
        }
      }
    }

    // Basic validation
    if ($update['name'] === '') { $errors[] = 'Name is required.'; }

    if (!$errors) {
      try {
        // Ensure we have a valid identifier
        if ($userId === '' && $userEmail === '' && $userName === '') {
          $errors[] = 'Unable to identify user. Please log out and log back in.';
        } else {
          $query = [];
          if ($userId !== '') { 
            $query['id'] = 'eq.' . $userId; 
          } elseif ($userEmail !== '') { 
            $query['email'] = 'eq.' . $userEmail; 
          } else { 
            // Use name with user_type filter to ensure we match the right admin
            $query['name'] = 'eq.' . $userName;
            $query['user_type'] = 'ilike.admin';
          }

          // Filter out optional empty fields to avoid unique/validation issues
          $payload = $update;
          if (isset($payload['email']) && $payload['email'] === '') { unset($payload['email']); }
          if (isset($payload['birthday']) && $payload['birthday'] === '') { unset($payload['birthday']); }
          if (isset($payload['address']) && $payload['address'] === '') { /* allow empty address if user cleared it */ }

          // PATCH expects a single object; our helper handles JSON encoding
          $patched = supabase_request('PATCH', 'users', $payload, $query);
          
          // Check if update was successful (PATCH with return=representation returns updated rows)
          if (!is_array($patched) || count($patched) === 0) {
            // No row was updated - user might not exist in DB yet
            // Try to create user if we have email
            if ($userEmail !== '' && $userEmail !== 'null') {
              $row = $payload;
              if (!isset($row['user_type'])) { $row['user_type'] = 'admin'; }
              if (!isset($row['name']) || $row['name'] === '') { 
                $row['name'] = $userName !== '' ? $userName : 'Admin'; 
              }
              if (!isset($row['email'])) { 
                $row['email'] = $userEmail; 
              }
              // Password is required - use a placeholder that user should change
              if (!isset($row['password'])) {
                $row['password'] = 'changeme123'; // Default password, user should change it
              }
              $inserted = supabase_insert('users', $row);
              if (is_array($inserted) && isset($inserted['id'])) {
                $userId = (string)$inserted['id'];
                $_SESSION['user']['id'] = (int)$inserted['id'];
              }
            } else {
              throw new RuntimeException('Update failed: no user found matching the provided identifier.');
            }
          } else {
            // Update successful - store the ID if we got it back
            if (isset($patched[0]['id'])) {
              $userId = (string)$patched[0]['id'];
              $_SESSION['user']['id'] = (int)$patched[0]['id'];
            }
          }

          // Refresh session user minimally
          foreach (['name','email','birthday','address'] as $k) {
            if (isset($update[$k])) { $_SESSION['user'][$k] = $update[$k]; }
          }
          if (isset($update['profile_image']) && $update['profile_image'] !== '') {
            $_SESSION['user']['avatar'] = $update['profile_image'];
          }
          if (isset($update['signature_image']) && $update['signature_image'] !== '') {
            $_SESSION['user']['signature_image'] = $update['signature_image'];
          }
          // Update session with resolved ID if we have it
          if ($userId !== '') {
            $_SESSION['user']['id'] = (int)$userId;
          }
          $user = $_SESSION['user'];
          $success = 'Profile updated successfully.';
        }
      } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        // Show more detailed error for debugging
        if (stripos($msg, 'Could not resolve host') !== false || stripos($msg, 'resolve host') !== false) {
          $errors[] = 'Connection error: Cannot reach Supabase server. Please check your internet connection and verify the Supabase project URL is correct.';
        } elseif (stripos($msg, 'timeout') !== false || stripos($msg, 'timed out') !== false) {
          $errors[] = 'Connection timeout: The server took too long to respond. Please try again.';
        } elseif (strpos($msg, '409') !== false || stripos($msg, 'duplicate') !== false) {
          $errors[] = 'Failed to update profile: email is already in use.';
        } elseif (strpos($msg, '422') !== false) {
          $errors[] = 'Failed to update profile: invalid data format.';
        } elseif (strpos($msg, '404') !== false) {
          $errors[] = 'Failed to update profile: user not found.';
        } elseif (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
          $errors[] = 'Authentication error: Invalid Supabase API key. Please check your configuration.';
        } else {
          $errors[] = 'Failed to update profile: ' . htmlspecialchars($msg);
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
      try {
        // Ensure we have a valid identifier
        if ($userId === '' && $userEmail === '' && $userName === '') {
          $errors[] = 'Unable to identify user. Please log out and log back in.';
        } else {
          $query = [];
          if ($userId !== '') { 
            $query['id'] = 'eq.' . $userId; 
          } elseif ($userEmail !== '') { 
            $query['email'] = 'eq.' . $userEmail; 
          } else { 
            // Use name with user_type filter to ensure we match the right admin
            $query['name'] = 'eq.' . $userName;
            $query['user_type'] = 'ilike.admin';
          }

          $patched = supabase_request('PATCH', 'users', ['password' => $newPassword], $query);
          if (!is_array($patched) || count($patched) === 0) {
            $errors[] = 'Failed to update password: user not found.';
          } else {
            $success = 'Password updated successfully.';
          }
        }
      } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        if (stripos($msg, 'Could not resolve host') !== false || stripos($msg, 'resolve host') !== false) {
          $errors[] = 'Connection error: Cannot reach Supabase server. Please check your internet connection and verify the Supabase project URL is correct.';
        } elseif (stripos($msg, 'timeout') !== false || stripos($msg, 'timed out') !== false) {
          $errors[] = 'Connection timeout: The server took too long to respond. Please try again.';
        } elseif (strpos($msg, '404') !== false) {
          $errors[] = 'Failed to update password: user not found.';
        } elseif (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
          $errors[] = 'Authentication error: Invalid Supabase API key. Please check your configuration.';
        } else {
          $errors[] = 'Failed to update password: ' . htmlspecialchars($msg);
        }
      }
    }
  }
}

$dbUser = fetch_current_user($user, $userId, $userEmail);

// Map fields with sensible defaults
$displayName = (string)($dbUser['name'] ?? $user['name'] ?? '');
if ($displayName !== '' && isset($dbUser['name']) && $dbUser['name'] !== '') {
  $_SESSION['user']['name'] = $displayName; // Keep session in sync
}
$profileImage = (string)($dbUser['profile_image'] ?? $user['avatar'] ?? '');
$signatureImage = (string)($dbUser['signature_image'] ?? $user['signature_image'] ?? '');
$department = (string)($dbUser['department'] ?? '');
$email = (string)($dbUser['email'] ?? $user['email'] ?? '');
$contactNumber = (string)($dbUser['contact_number'] ?? '');
$birthday = (string)($dbUser['birthday'] ?? '');
$address = (string)($dbUser['address'] ?? '');
$areaOfWork = (string)($dbUser['area_of_work'] ?? '');

// Calculate pending work request count
$pendingCount = 0;
try {
  $summaryRows = supabase_request('GET', 'work_request', null, [
    'select' => 'status,count=status',
    'group' => 'status'
  ]);
  if (is_array($summaryRows)) {
    foreach ($summaryRows as $row) {
      $label = strtolower((string)($row['status'] ?? ''));
      $count = (int)($row['count'] ?? 0);
      if ($label === 'waiting for staff') {
        $pendingCount += $count;
      } elseif ($label === 'in progress' || $label === 'in-progress' || $label === 'for pickup/confirmation' || $label === 'waiting for pickup/confirmation' || $label === 'waiting for pick up/confirmation') {
        $pendingCount += $count;
      } elseif ($label === 'pending') {
        $pendingCount += $count;
      } elseif ($label !== 'completed' && $label !== 'task completed' && $label !== 'cancelled' && $label !== 'canceled') {
        // track other statuses as pending
        $pendingCount += $count;
      }
    }
  }
} catch (Throwable $e) {
  // leave as 0
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Information</title>
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
  .avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: #ddd; }
  .name { font-weight: 600; color: var(--maroon-700); }
  .container { max-width: 1100px; margin: 20px auto; padding: 0 16px; }
  .actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
  .btn { display: inline-block; text-decoration: none; text-align: center; padding: 14px 16px; border-radius: 10px; border: 1px solid #e5e5e5; background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .15s ease, border-color .15s ease, transform .1s ease; }
  .btn:hover { background: #fff7f8; border-color: var(--maroon-400); }
  .btn:active { transform: translateY(1px); }
  .card { border: 1px solid #eee; border-radius: 12px; padding: 16px; }
  .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
  .field { display: flex; flex-direction: column; gap: 6px; }
  label { font-weight: 600; color: var(--maroon-700); }
  input, textarea { padding: 10px 12px; border-radius: 8px; border: 1px solid #e5e5e5; font: inherit; }
  textarea { min-height: 80px; resize: vertical; }
  .row { display: flex; gap: 12px; align-items: center; }
  .notice { padding: 10px 12px; border-radius: 8px; }
  .error { background: #fff1f2; color: #7a1b2a; border: 1px solid #ffd5da; }
  .success { background: #ecfeff; color: #0b6b74; border: 1px solid #cffafe; }
  @media (max-width: 640px) {
    .actions { grid-template-columns: 1fr; }
    .grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
  <header class="topbar">
    <div class="brand">RCC Admin</div>
    <div class="profile">
      
      <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main class="container">
    <section class="actions" aria-label="Admin actions">
      <a class="btn" href="/ERS/meso/dashboard.php">Back to Home</a>
      <button class="btn" type="button">Staffs</button>
      <a class="btn" href="/ERS/meso/workRequest.php" style="position: relative;">
        Work Request
        <?php if ($pendingCount > 0): ?>
          <span style="position: absolute; top: -6px; right: -6px; background: #dc2626; color: #fff; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;"><?php echo $pendingCount; ?></span>
        <?php endif; ?>
      </a>
      <button class="btn" type="button">Reports</button>
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
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($email); ?>">
          </div>
          <div class="field">
            <label for="birthday">Birthday</label>
            <input id="birthday" name="birthday" type="date" value="<?php echo htmlspecialchars($birthday); ?>">
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label for="address">Address</label>
            <textarea id="address" name="address"><?php echo htmlspecialchars($address); ?></textarea>
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label for="profile_image">Profile Image</label>
            <?php if ($profileImage !== ''): ?>
              <div class="row"><img src="../<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" style="height:60px;border:1px solid #eee;border-radius:6px;background:#fff;"></div>
            <?php endif; ?>
            <input id="profile_image" name="profile_image" type="file" accept="image/*">
            <div class="row" style="color:#6b7280;font-size:12px;">Upload to add or replace your profile image.</div>
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label for="signature_image">Signature</label>
            <?php if ($signatureImage !== ''): ?>
              <div class="row"><img src="../<?php echo htmlspecialchars($signatureImage); ?>" alt="Signature" style="height:60px;border:1px solid #eee;border-radius:6px;background:#fff;"></div>
            <?php endif; ?>
            <input id="signature_image" name="signature_image" type="file" accept="image/*">
            <div class="row" style="color:#6b7280;font-size:12px;">Upload to add or replace your signature.</div>
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
  <script>
    // Replace the nav button for the current page with Back to Home
    (function(){
      try {
        var links = document.querySelectorAll('.actions a.btn');
        var here = location.pathname.replace(/\/+/, '/');
        for (var i=0;i<links.length;i++) {
          var a = links[i];
          var href = a.getAttribute('href') || '';
          if (!href) continue;
          var abs = document.createElement('a'); abs.href = href; var path = abs.pathname.replace(/\/+/, '/');
          if (path === here) {
            a.textContent = 'Back to Home';
            a.setAttribute('href', '/ERS/meso/dashboard.php');
            break;
          }
        }
      } catch (e) {}
    })();
  </script>
</body>
</html>


