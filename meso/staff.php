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
        $_SESSION['user']['name'] = $displayName;
      }
    }
  }
} catch (Throwable $e) {}

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
        foreach ($update as $key => $value) {
          if ($value === '' && in_array($key, ['email', 'contact_number', 'birthday', 'address', 'area_of_work', 'department'])) {
            $update[$key] = null;
          }
        }
        supabase_request('PATCH', 'users', $update, ['id' => 'eq.' . $id]);
        $success = 'User updated successfully.';
        $staff = fetch_staff_users();
      } catch (Throwable $e) {
        $errors[] = 'Failed to update user.';
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
  --maroon-dark: #5a0f1b;
  --maroon: #7a1b2a;
  --maroon-light: #a42b43;
  --offwhite: #fdf7f8;
  --text: #222;
}

body {
  margin: 0;
  font-family: Arial, sans-serif;
  background: #fff;
  color: var(--text);
}

.topbar {
  display: flex;
  justify-content: space-between;
  padding: 14px 16px;
  background: var(--maroon);
  color: #fff;
}

.brand {
  font-size: 20px;
  font-weight: bold;
}

.btn {
  padding: 8px 14px;
  border-radius: 8px;
  font-weight: 600;
  border: 1px solid var(--maroon-light);
  color: var(--maroon-dark);
  background: #fff;
  cursor: pointer;
}

.btn:hover {
  background: var(--offwhite);
}

.container {
  max-width: 1200px;
  margin: 20px auto;
  padding: 10px;
}

table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
}

th {
  background: var(--maroon);
  color: #fff;
  padding: 10px;
}

td {
  border-bottom: 1px solid #ddd;
  padding: 8px;
}

.thumb {
  height: 40px;
  border-radius: 6px;
  border: 1px solid #ccc;
}
.edit-row {
  background: #f9f1f2;
}

input, textarea {
  width: 100%;
  padding: 6px;
  border: 1px solid #ccc;
  border-radius: 6px;
}

label {
  font-weight: bold;
  color: var(--maroon-dark);
}

.success {
  background: #e5fff3;
  border-left: 4px solid #0a6b3b;
  padding: 10px;
}

.error {
  background: #ffe5e7;
  border-left: 4px solid #7a1b2a;
  padding: 10px;
}

</style>

</head>
<body>

<header class="topbar">
  <div class="brand">RCC Admin</div>
  <div>
    <?php echo htmlspecialchars($displayName); ?> |
    <a class="btn" href="../logout.php">Logout</a>
  </div>
</header>

<div class="container">

  <?php if ($errors): ?>
    <div class="error"><?php echo implode(' ', $errors); ?></div>
  <?php elseif ($success): ?>
    <div class="success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Name</th><th>Email</th><th>Contact</th><th>Department</th><th>Area</th>
        <th>Birthday</th><th>Address</th><th>Profile</th><th>Signature</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>

    <?php foreach ($staff as $s): ?>
      <?php
        $sid = $s["id"];
      ?>
      <tr>
        <td><?= htmlspecialchars($s["name"]) ?></td>
        <td><?= htmlspecialchars($s["email"]) ?></td>
        <td><?= htmlspecialchars($s["contact_number"]) ?></td>
        <td><?= htmlspecialchars($s["department"]) ?></td>
        <td><?= htmlspecialchars($s["area_of_work"]) ?></td>
        <td><?= htmlspecialchars($s["birthday"]) ?></td>
        <td><?= htmlspecialchars($s["address"]) ?></td>

        <td>
          <?php if ($s["profile_image"]): ?>
            <img src="../<?= htmlspecialchars($s["profile_image"]) ?>" class="thumb">
          <?php else: ?>
            None
          <?php endif; ?>
        </td>

        <td>
          <?php if ($s["signature_image"]): ?>
            <img src="../<?= htmlspecialchars($s["signature_image"]) ?>" class="thumb">
          <?php else: ?>
            None
          <?php endif; ?>
        </td>

        <td>
          <button class="btn" onclick="toggleEdit('<?= $sid ?>')">Edit</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete user?');">
            <input type="hidden" name="id" value="<?= $sid ?>">
            <button class="btn" name="action" value="delete">Delete</button>
          </form>
        </td>
      </tr>

      <!-- EDIT FORM -->
      <tr id="edit-<?= $sid ?>" class="edit-row" style="display:none;">
        <td colspan="10">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= $sid ?>">

            <label>Name *</label>
            <input type="text" name="name" value="<?= htmlspecialchars($s["name"]) ?>" required>

            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($s["email"]) ?>">

            <label>Contact</label>
            <input type="text" name="contact_number" value="<?= htmlspecialchars($s["contact_number"]) ?>">

            <label>Department</label>
            <input type="text" name="department" value="<?= htmlspecialchars($s["department"]) ?>">

            <label>Area of Work</label>
            <input type="text" name="area_of_work" value="<?= htmlspecialchars($s["area_of_work"]) ?>">

            <label>Birthday</label>
            <input type="date" name="birthday" value="<?= htmlspecialchars($s["birthday"]) ?>">

            <label>Address</label>
            <textarea name="address"><?= htmlspecialchars($s["address"]) ?></textarea>

            <label>Profile Image</label>
            <input type="file" name="profile_image_file">

            <label>Signature Image</label>
            <input type="file" name="signature_image_file">

            <button class="btn" name="action" value="update">Save</button>
          </form>
        </td>
      </tr>

    <?php endforeach; ?>

    </tbody>
  </table>

</div>

<script>
function toggleEdit(id) {
  const row = document.getElementById("edit-" + id);
  row.style.display = row.style.display === "none" ? "table-row" : "none";
}
</script>

</body>
</html>
