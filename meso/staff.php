<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
  header('Location: ../main/index.php');
  exit;
}

require_once __DIR__ . '/../supabase_rest.php';

// Fetch fullname from database - prioritize by name field
$displayName = (string)($user['name'] ?? 'Admin');
try {
  $query = ['select' => 'name', 'limit' => 1];
  $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
  
  if ($userName !== '') {
    // Try to fetch by name first (with admin role filter)
    $query['name'] = 'eq.' . $userName;
    $query['user_type'] = 'ilike.admin';
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
        $_SESSION['user']['name'] = $displayName; // Keep session in sync
      }
    }
  }
} catch (Throwable $e) {
  // Fall back to session name
}

$errors = [];
$success = '';

function fetch_staff_users(): array {
  try {
    $rows = supabase_request('GET', 'users', null, [
      'select' => 'id,name,email,user_type,contact_number,birthday,address,area_of_work,department,profile_image,signature_image',
      'user_type' => 'ilike.staff',
      'order' => 'name.asc'
    ]);
    return is_array($rows) ? $rows : [];
  } catch (Throwable $e) {
    return [];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
  if ($id === '') {
    $errors[] = 'Missing user id.';
  } else if ($action === 'delete') {
    try {
      supabase_request('DELETE', 'users', null, ['id' => 'eq.' . $id]);
      $success = 'User deleted.';
    } catch (Throwable $e) {
      $errors[] = 'Failed to delete user.';
    }
  } else if ($action === 'update') {
    $update = [];
    $update['name'] = trim((string)($_POST['name'] ?? ''));
    $update['email'] = trim((string)($_POST['email'] ?? ''));
    $update['contact_number'] = trim((string)($_POST['contact_number'] ?? ''));
    $update['birthday'] = (string)($_POST['birthday'] ?? '');
    $update['address'] = trim((string)($_POST['address'] ?? ''));
    $update['area_of_work'] = trim((string)($_POST['area_of_work'] ?? ''));
    $update['department'] = trim((string)($_POST['department'] ?? ''));

    $removeProfile = isset($_POST['remove_profile_image']) && $_POST['remove_profile_image'] === '1';
    $removeSignature = isset($_POST['remove_signature_image']) && $_POST['remove_signature_image'] === '1';
    if ($removeProfile) { $update['profile_image'] = ''; }
    if ($removeSignature) { $update['signature_image'] = ''; }

    $uploadsDir = realpath(__DIR__ . '/../uploads');
    if ($uploadsDir === false) { $uploadsDir = __DIR__ . '/../uploads'; }
    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }

    $allowedExt = ['jpg','jpeg','png','gif','webp'];
    $maxBytes = 5 * 1024 * 1024;

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

    $uploadedProfile = $processUpload('profile_image_file');
    $uploadedSignature = $processUpload('signature_image_file');
    if ($uploadedProfile !== null) { $update['profile_image'] = $uploadedProfile; }
    if ($uploadedSignature !== null) { $update['signature_image'] = $uploadedSignature; }

    if ($update['name'] === '') { $errors[] = 'Name is required.'; }

    if (!$errors) {
      try {
        // Convert empty strings to null for optional fields (except images which can be empty strings to clear)
        foreach ($update as $key => $value) {
          if ($value === '' && in_array($key, ['email', 'contact_number', 'birthday', 'address', 'area_of_work', 'department'])) {
            $update[$key] = null;
          }
        }
        supabase_request('PATCH', 'users', $update, ['id' => 'eq.' . $id]);
        $success = 'User updated successfully.';
        // Refresh staff list to show updated data
        $staff = fetch_staff_users();
      } catch (Throwable $e) {
        $errors[] = 'Failed to update user: ' . (strpos($e->getMessage(), 'duplicate key') !== false ? 'Email already exists.' : $e->getMessage());
      }
    }
  }
}

$staff = fetch_staff_users();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Staff</title>
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
  .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
  .btn { display: inline-block; text-decoration: none; text-align: center; padding: 10px 14px; border-radius: 10px; border: 1px solid #e5e5e5; background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .15s ease, border-color .15s ease, transform .1s ease; }
  .btn:hover { background: #fff7f8; border-color: var(--maroon-400); }
  .btn:active { transform: translateY(1px); }
  .notice { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
  .error { background: #fff1f2; color: #7a1b2a; border: 1px solid #ffd5da; }
  .success { background: #ecfeff; color: #0b6b74; border: 1px solid #cffafe; }
  .thumb { height: 36px; border-radius: 6px; border: 1px solid #eee; }
  table { width: 100%; border-collapse: separate; border-spacing: 0; }
  thead th { position: sticky; top: 0; background: #fff; text-align: left; font-weight: 700; color: var(--maroon-700); border-bottom: 2px solid #eee; padding: 10px; }
  tbody td { border-bottom: 1px solid #f0f0f0; padding: 10px; vertical-align: top; }
  tbody tr:hover { background: #fff7f8; }
  .nowrap { white-space: nowrap; }
  .muted { color: #666; font-size: 12px; }
  .edit-row { display: none; background: #fafafa; }
  .edit-row td { border-bottom: 1px solid #eee; }
  .row-controls { display: flex; gap: 8px; align-items: center; }
  .small { padding: 8px 10px; border-radius: 8px; }
  input[type="text"], input[type="email"], input[type="date"], textarea { 
    width: 100%; 
    padding: 8px; 
    border: 1px solid #e5e5e5; 
    border-radius: 6px; 
    font-size: 14px;
    font-family: inherit;
  }
  input[type="text"]:focus, input[type="email"]:focus, input[type="date"]:focus, textarea:focus {
    outline: none;
    border-color: var(--maroon-400);
    box-shadow: 0 0 0 3px rgba(164, 43, 67, 0.1);
  }
  label {
    display: block;
    margin-bottom: 4px;
    font-weight: 600;
    color: var(--maroon-700);
    font-size: 14px;
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
      <a class="btn" href="/ERS/meso/information.php">Information</a>
      <a class="btn" href="/ERS/meso/dashboard.php">Back to Home</a>
      <a class="btn" href="/ERS/meso/workRequest.php">Work Request</a>
      <button class="btn" type="button">Reports</button>
    </section>

    <?php if ($errors): ?>
      <div class="notice error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php elseif ($success !== ''): ?>
      <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <section aria-label="Staff list">
      <table role="table">
        <thead>
          <tr>
            <th style="width: 15%">Name</th>
            <th style="width: 12%">Email</th>
            <th style="width: 10%">Contact</th>
            <th style="width: 12%">Department</th>
            <th style="width: 12%">Area of Work</th>
            <th style="width: 10%">Birthday</th>
            <th>Address</th>
            <th style="width: 8%">Profile</th>
            <th style="width: 8%">Signature</th>
            <th style="width: 12%" class="nowrap">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staff as $s): ?>
            <?php
              $sid = (string)($s['id'] ?? '');
              $sname = (string)($s['name'] ?? '');
              $semail = (string)($s['email'] ?? '');
              $scontact = (string)($s['contact_number'] ?? '');
              $sdept = (string)($s['department'] ?? '');
              $sbirthday = (string)($s['birthday'] ?? '');
              $saddress = (string)($s['address'] ?? '');
              $sarea = (string)($s['area_of_work'] ?? '');
              $sprofile = (string)($s['profile_image'] ?? '');
              $ssign = (string)($s['signature_image'] ?? '');
            ?>
            <tr>
              <td><?php echo htmlspecialchars($sname); ?></td>
              <td><?php echo htmlspecialchars($semail); ?></td>
              <td><?php echo htmlspecialchars($scontact); ?></td>
              <td><?php echo htmlspecialchars($sdept); ?></td>
              <td><?php echo htmlspecialchars($sarea); ?></td>
              <td><?php echo htmlspecialchars($sbirthday); ?></td>
              <td><?php echo nl2br(htmlspecialchars($saddress)); ?></td>
              <td>
                <?php if ($sprofile !== ''): ?>
                  <img class="thumb" src="<?php echo htmlspecialchars('../' . $sprofile); ?>" alt="Profile">
                <?php else: ?>
                  <span class="muted">None</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($ssign !== ''): ?>
                  <img class="thumb" src="<?php echo htmlspecialchars('../' . $ssign); ?>" alt="Signature">
                <?php else: ?>
                  <span class="muted">None</span>
                <?php endif; ?>
              </td>
              <td class="nowrap">
                <div class="row-controls">
                  <button class="btn small" type="button" data-edit-toggle="<?php echo htmlspecialchars($sid); ?>">Edit</button>
                  <form method="post" onsubmit="return confirm('Delete this staff user?');" style="margin:0;">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($sid); ?>">
                    <button class="btn small" name="action" value="delete" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <tr class="edit-row" id="edit-<?php echo htmlspecialchars($sid); ?>">
              <td colspan="10">
                <form method="post" enctype="multipart/form-data" style="display:grid; gap: 12px; padding: 16px;">
                  <input type="hidden" name="id" value="<?php echo htmlspecialchars($sid); ?>">
                  <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px;">
                    <div>
                      <label for="name-<?php echo htmlspecialchars($sid); ?>" style="display:block; margin-bottom: 4px; font-weight: 600; color: var(--maroon-700);">Name *</label>
                      <input id="name-<?php echo htmlspecialchars($sid); ?>" name="name" type="text" value="<?php echo htmlspecialchars($sname); ?>" required style="padding: 8px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                      <label for="email-<?php echo htmlspecialchars($sid); ?>" style="display:block; margin-bottom: 4px; font-weight: 600; color: var(--maroon-700);">Email</label>
                      <input id="email-<?php echo htmlspecialchars($sid); ?>" name="email" type="email" value="<?php echo htmlspecialchars($semail); ?>" style="padding: 8px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                      <label for="contact-<?php echo htmlspecialchars($sid); ?>" style="display:block; margin-bottom: 4px; font-weight: 600; color: var(--maroon-700);">Contact Number</label>
                      <input id="contact-<?php echo htmlspecialchars($sid); ?>" name="contact_number" type="text" value="<?php echo htmlspecialchars($scontact); ?>" style="padding: 8px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                      <label for="dept-<?php echo htmlspecialchars($sid); ?>" style="display:block; margin-bottom: 4px; font-weight: 600; color: var(--maroon-700);">Department</label>
                      <input id="dept-<?php echo htmlspecialchars($sid); ?>" name="department" type="text" value="<?php echo htmlspecialchars($sdept); ?>" style="padding: 8px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                      <label for="area-<?php echo htmlspecialchars($sid); ?>" style="display:block; margin-bottom: 4px; font-weight: 600; color: var(--maroon-700);">Area of Work</label>
                      <input id="area-<?php echo htmlspecialchars($sid); ?>" name="area_of_work" type="text" value="<?php echo htmlspecialchars($sarea); ?>" style="padding: 8px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                      <label for="birthday-<?php echo htmlspecialchars($sid); ?>" style="display:block; margin-bottom: 4px; font-weight: 600; color: var(--maroon-700);">Birthday</label>
                      <input id="birthday-<?php echo htmlspecialchars($sid); ?>" name="birthday" type="date" value="<?php echo htmlspecialchars($sbirthday); ?>" style="padding: 8px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px;">
                    </div>
                  </div>
                  <div>
                    <label for="address-<?php echo htmlspecialchars($sid); ?>" style="display:block; margin-bottom: 4px; font-weight: 600; color: var(--maroon-700);">Address</label>
                    <textarea id="address-<?php echo htmlspecialchars($sid); ?>" name="address" rows="3" style="padding: 8px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px; width: 100%; font-family: inherit;"><?php echo htmlspecialchars($saddress); ?></textarea>
                  </div>
                  <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: center;">
                    <div>
                      <label>Profile Image</label>
                      <div class="row-controls">
                        <?php if ($sprofile !== ''): ?>
                          <img class="thumb" src="<?php echo htmlspecialchars('../' . $sprofile); ?>" alt="Profile">
                          <label class="nowrap"><input type="checkbox" name="remove_profile_image" value="1"> Remove</label>
                        <?php else: ?>
                          <span class="muted">None</span>
                        <?php endif; ?>
                      </div>
                      <input name="profile_image_file" type="file" accept="image/*">
                    </div>
                    <div>
                      <label>Signature Image</label>
                      <div class="row-controls">
                        <?php if ($ssign !== ''): ?>
                          <img class="thumb" src="<?php echo htmlspecialchars('../' . $ssign); ?>" alt="Signature">
                          <label class="nowrap"><input type="checkbox" name="remove_signature_image" value="1"> Remove</label>
                        <?php else: ?>
                          <span class="muted">None</span>
                        <?php endif; ?>
                      </div>
                      <input name="signature_image_file" type="file" accept="image/*">
                    </div>
                  </div>
                  <div class="row-controls" style="justify-content: flex-end;">
                    <button class="btn" name="action" value="update" type="submit">Save Changes</button>
                    <button class="btn" type="button" data-edit-toggle="<?php echo htmlspecialchars($sid); ?>">Cancel</button>
                  </div>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$staff): ?>
            <tr><td colspan="10">No staff users found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </main>
  <script>
    document.querySelectorAll('[data-edit-toggle]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-edit-toggle');
        var row = document.getElementById('edit-' + id);
        if (row) {
          row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
        }
      });
    });
    
    // Auto-close edit form after successful update
    <?php if ($success !== ''): ?>
      // Close all open edit rows after successful update
      document.querySelectorAll('.edit-row').forEach(function(row) {
        row.style.display = 'none';
      });
    <?php endif; ?>
  </script>
</body>
</html>
