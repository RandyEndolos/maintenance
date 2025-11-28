<?php
  $successMessage = '';
  $errorMessage = '';
  $tempPasswordPlain = '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Inline DB env for local run; move to Apache SetEnv in production
    if ((getenv('SUPABASE_DB_URL') ?: '') === '' && (getenv('SUPABASE_DB_HOST') ?: '') === '') {
      putenv('SUPABASE_DB_HOST=db.kmrqgqodgwwseaotbsvt.supabase.co');
      putenv('SUPABASE_DB_PORT=5432');
      putenv('SUPABASE_DB_NAME=postgres');
      putenv('SUPABASE_DB_USER=postgres');
      // '@' is allowed here since we are not using URL form
      putenv('SUPABASE_DB_PASS=RandyCrishinephilCecile@123');
      putenv('SUPABASE_DB_SSLMODE=require');
    }

    // Prefer REST for insertion to avoid DSN issues; set REST env inline if missing
    if ((getenv('SUPABASE_URL') ?: '') === '') {
      putenv('SUPABASE_URL=https://kmrqgqodgwwseaotbsvt.supabase.co');
    }
    if ((getenv('SUPABASE_ANON_KEY') ?: '') === '') {
      putenv('SUPABASE_ANON_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImttcnFncW9kZ3d3c2Vhb3Ric3Z0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MTY1OTYsImV4cCI6MjA3NzI5MjU5Nn0._HFdvA-wDeMkU6TOij0IIxsbJCSnoOEtLP8yKFUDAnE');
    }
    // Prefer REST for insertion to avoid DSN issues; fall back to PDO if needed
    require_once __DIR__ . '/../supabase_rest.php';

    // Gather and normalize inputs
    $userType = isset($_POST['userType']) ? trim($_POST['userType']) : '';
    $fullName = isset($_POST['fullName']) ? trim($_POST['fullName']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $contactNumber = isset($_POST['contactNumber']) ? trim($_POST['contactNumber']) : '';
    $department = isset($_POST['department']) ? trim($_POST['department']) : '';
    $area = isset($_POST['area']) ? trim($_POST['area']) : '';

    // Validate required fields by type
    $errors = [];
    if ($userType === '') { $errors[] = 'Type of user is required.'; }
    if ($fullName === '') { $errors[] = 'Name is required.'; }

    $isStaff = ($userType === 'Staff');
    $isRequester = ($userType === 'Requester');
    $isAdmin = ($userType === 'Admin');

    if ($isRequester || $isAdmin) {
      if ($email === '') { $errors[] = 'Email is required for Requester and Admin.'; }
      elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Email format is invalid.'; }
    }
    if ($isStaff) {
      if ($contactNumber === '') { $errors[] = 'Contact number is required for Staff.'; }
      if ($area === '') { $errors[] = 'Area is required for Staff.'; }
    }
    if ($isRequester) {
      if ($department === '') { $errors[] = 'Department is required for Requester.'; }
    }

    // Handle image upload (optional)
    $storedImagePath = null;
    if (isset($_FILES['profileImage']) && is_array($_FILES['profileImage']) && ($_FILES['profileImage']['error'] !== UPLOAD_ERR_NO_FILE)) {
      if ($_FILES['profileImage']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['profileImage']['tmp_name'];
        $size = (int)$_FILES['profileImage']['size'];
        if ($size > 5 * 1024 * 1024) {
          $errors[] = 'Image exceeds 5MB limit.';
        } else {
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $mime = $finfo->file($tmp) ?: 'application/octet-stream';
          $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
          if (!isset($allowed[$mime])) {
            $errors[] = 'Only JPG, PNG, or GIF images are allowed.';
          } else {
            $ext = $allowed[$mime];
            $uploadDir = __DIR__ . '/../uploads';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
            $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower(pathinfo($_FILES['profileImage']['name'], PATHINFO_FILENAME)));
            if ($safeName === '' ) { $safeName = 'image'; }
            $filename = $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = $uploadDir . '/' . $filename;
            if (!@move_uploaded_file($tmp, $dest)) {
              $errors[] = 'Failed to save uploaded image.';
            } else {
              // Store relative path from project root
              $storedImagePath = 'uploads/' . $filename;
            }
          }
        }
      } else {
        $errors[] = 'Image upload failed (code ' . (int)$_FILES['profileImage']['error'] . ').';
      }
    }

    if (empty($errors)) {
      try {
        // Generate a random temporary password (12 chars alphanumeric)
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@$!%*?';
        $len = 12;
        $pwd = '';
        for ($i = 0; $i < $len; $i++) {
          $pwd .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $tempPasswordPlain = $pwd; // display to user
        $passwordHash = password_hash($pwd, PASSWORD_DEFAULT);

        // Insert via Supabase REST
        $insertRow = [
          'user_type' => $userType,
          'name' => $fullName,
          'profile_image' => $storedImagePath,
          'department' => $department !== '' ? $department : null,
          'email' => $email !== '' ? $email : null,
          'contact_number' => $contactNumber !== '' ? $contactNumber : null,
          'birthday' => null,
          'address' => null,
          'area_of_work' => $area !== '' ? $area : null,
          'password' => $passwordHash,
        ];
        $inserted = supabase_insert('users', $insertRow);
        $successMessage = 'Account request submitted successfully.';
      } catch (Throwable $e) {
        $errorMessage = 'Failed to submit request: ' . $e->getMessage();
      }
    } else {
      $errorMessage = implode("\n", $errors);
    }
  }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ERS | Request an Account</title>
<style>
  :root {
    --maroon-700: #5a0f1b;
    --maroon-600: #7a1b2a;
    --maroon-500: #8b1e33;
    --maroon-300: #c7475e;
    --white: #ffffff;
    --offwhite: #f9f6f7;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
    background: linear-gradient(160deg, var(--maroon-700), var(--maroon-500));
    color: var(--white);
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 20px 16px;
  }

  .card {
    background: var(--offwhite);
    color: #2a2a2a;
    width: min(92vw, 760px);
    padding: 28px 24px;
    border-radius: 16px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
    border: 1px solid rgba(255,255,255,0.25);
  }

  .header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
  }

  .title {
    margin: 0;
    font-size: 24px;
    color: var(--maroon-700);
  }

  .back-link {
    color: var(--white);
    text-decoration: none;
    background: var(--maroon-600);
    padding: 8px 12px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 2px solid transparent;
  }

  .back-link:hover { background: var(--maroon-500); }

  form {
    margin-top: 8px;
    display: grid;
    gap: 14px;
  }

  .row {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .field { display: flex; flex-direction: column; }

  label {
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--maroon-700);
  }

  input[type="text"], input[type="email"], input[type="tel"], select {
    padding: 12px 12px;
    border: 2px solid #e2e2e2;
    border-radius: 10px;
    font-size: 14px;
    background: #ffffff;
    outline: none;
    transition: border-color 160ms ease, box-shadow 160ms ease;
  }

  input[type="file"] {
    padding: 10px;
    border: 2px dashed #e2e2e2;
    border-radius: 10px;
    background: #ffffff;
  }

  input:focus, select:focus {
    border-color: var(--maroon-300);
    box-shadow: 0 0 0 3px rgba(139, 30, 51, 0.12);
  }

  .hint { font-size: 12px; color: #666; margin-top: 6px; }

  .actions { margin-top: 6px; display: flex; gap: 10px; }

  .btn {
    appearance: none;
    padding: 12px 16px;
    font-weight: 700;
    font-size: 15px;
    border-radius: 10px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: transform 120ms ease, background-color 160ms ease, color 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
  }
  .btn:active { transform: translateY(1px); }
  .btn-primary { background: var(--maroon-600); color: var(--white); box-shadow: 0 6px 16px rgba(122, 27, 42, 0.35); }
  .btn-primary:hover { background: var(--maroon-500); }

  .hidden { display: none; }

  @media (max-width: 640px) {
    .row { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
  <div class="card">
    <div class="header">
      <h1 class="title">Request an Account</h1>
      <a class="back-link" href="index.php" aria-label="Back to welcome">⟵ Back</a>
    </div>

    <?php if ($errorMessage !== ''): ?>
      <div style="background:#ffe5e7;color:#7a1b2a;border:2px solid #f2b6be;padding:12px 14px;border-radius:10px;margin-bottom:12px;white-space:pre-line;">❌ <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
      <div style="background:#e9fff1;color:#165c2b;border:2px solid #b6f2c9;padding:12px 14px;border-radius:10px;margin-bottom:12px;">✅ <?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <?php if ($tempPasswordPlain !== ''): ?>
      <div style="background:#fff7f8;color:#5a0f1b;border:2px dashed #a42b43;padding:14px 16px;border-radius:12px;margin-bottom:16px;">
        <strong>Temporary password (save this now):</strong>
        <div style="font-family:monospace;font-size:18px;margin-top:6px;"><?php echo htmlspecialchars($tempPasswordPlain); ?></div>
        <div class="hint" style="margin-top:8px;">This is a temporary password for first login. Please change it immediately after logging in.</div>
      </div>
    <?php endif; ?>

    <form id="requestForm" method="post" action="#" enctype="multipart/form-data" novalidate>
      <div class="row">
        <div class="field">
          <label for="userType">Type of user</label>
          <select id="userType" name="userType" required>
            <option value="" disabled selected>Select type</option>
            <option value="Staff">Staff</option>
            <option value="Requester">Requester</option>
            <option value="Admin">Admin</option>
          </select>
        </div>
        <div class="field">
          <label for="fullName">Name</label>
          <input type="text" id="fullName" name="fullName" placeholder="Enter full name" value="<?php echo isset($fullName) ? htmlspecialchars($fullName) : '';?>" required>
        </div>
      </div>

      <div class="row">
        <div class="field">
          <label for="profileImage">Profile Image</label>
          <input type="file" id="profileImage" name="profileImage" accept="image/*">
          <div class="hint">Accepted: JPG, PNG, GIF. Max ~5MB.</div>
        </div>
        <div class="field" id="emailField">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" placeholder="name@example.com" value="<?php echo isset($email) ? htmlspecialchars($email) : '';?>">
        </div>
      </div>

      <div class="row">
        <div class="field hidden" id="contactNumberField">
          <label for="contactNumber">Contact number</label>
          <input type="tel" id="contactNumber" name="contactNumber" placeholder="e.g. 0917 123 4567" value="<?php echo isset($contactNumber) ? htmlspecialchars($contactNumber) : '';?>">
        </div>
        <div class="field hidden" id="departmentField">
          <label for="department">Department</label>
          <input type="text" id="department" name="department" placeholder="e.g. Finance" value="<?php echo isset($department) ? htmlspecialchars($department) : '';?>">
        </div>
      </div>

      <div class="row">
        <div class="field hidden" id="areaField">
          <label for="area">Area</label>
          <input type="text" id="area" name="area" placeholder="e.g. Building A" value="<?php echo isset($area) ? htmlspecialchars($area) : '';?>">
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="btn btn-primary">Submit Request</button>
      </div>
    </form>
  </div>

<script>
  (function() {
    const userType = document.getElementById('userType');
    const emailField = document.getElementById('emailField');
    const emailInput = document.getElementById('email');
    const contactNumberField = document.getElementById('contactNumberField');
    const contactNumberInput = document.getElementById('contactNumber');
    const departmentField = document.getElementById('departmentField');
    const departmentInput = document.getElementById('department');
    const areaField = document.getElementById('areaField');
    const areaInput = document.getElementById('area');

    function setVisible(el, visible) {
      el.classList.toggle('hidden', !visible);
    }

    function setRequired(input, required) {
      if (!input) return;
      if (required) {
        input.setAttribute('required', 'required');
      } else {
        input.removeAttribute('required');
      }
    }

    function updateVisibility() {
      const type = userType.value;

      const isStaff = type === 'Staff';
      const isRequester = type === 'Requester';
      const isAdmin = type === 'Admin';

      // Email: requester or admin
      setVisible(emailField, isRequester || isAdmin);
      setRequired(emailInput, isRequester || isAdmin);

      // Contact number: staff
      setVisible(contactNumberField, isStaff);
      setRequired(contactNumberInput, isStaff);

      // Department: requester
      setVisible(departmentField, isRequester);
      setRequired(departmentInput, isRequester);

      // Area: staff
      setVisible(areaField, isStaff);
      setRequired(areaInput, isStaff);
    }

    userType.addEventListener('change', updateVisibility);
    updateVisibility();
  })();
</script>
</body>
</html>

