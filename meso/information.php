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

// --- Resolve user logic omitted for brevity (same as your original code) ---

// Fetch current user function
function fetch_current_user(array $user, string $userId, string $userEmail): array {
    // Same function logic as your original code
    return $user; // simplified for brevity
}

// Handle profile updates & password updates
// Same logic as original code omitted for brevity

$dbUser = fetch_current_user($user, $userId, $userEmail);

// Map fields with sensible defaults
$displayName = (string)($dbUser['name'] ?? $user['name'] ?? '');
$profileImage = (string)($dbUser['profile_image'] ?? $user['avatar'] ?? '');
$signatureImage = (string)($dbUser['signature_image'] ?? $user['signature_image'] ?? '');
$email = (string)($dbUser['email'] ?? $user['email'] ?? '');
$birthday = (string)($dbUser['birthday'] ?? '');
$address = (string)($dbUser['address'] ?? '');

// Calculate pending work request count
$pendingCount = 0;
// logic omitted for brevity
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
    --maroon-500: #8b1f33;
    --maroon-400: #a42b43;
    --maroon-light: #7a1b2a;
    --text-light: #fff;
    --text-muted: #ffdede;
    --bg-card: #7a1b2a;
    --bg-input: #8b1f33;
    --bg-btn: #7a1b2a;
    --bg-btn-hover: #a42b43;
    --border-color: #a42b43;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: var(--maroon-700); color: var(--text-light); }
  .topbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--maroon-600); background: var(--maroon-600); }
  .brand { font-weight: 700; color: var(--text-light); }
  .profile { display: flex; align-items: center; gap: 10px; }
  .name { font-weight: 600; color: var(--text-light); }
  .container { max-width: 1100px; margin: 20px auto; padding: 0 16px; }
  .actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
  .btn { display: inline-block; text-decoration: none; text-align: center; padding: 14px 16px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-btn); cursor: pointer; font-weight: 600; color: var(--text-light); transition: background .15s ease, border-color .15s ease, transform .1s ease; }
  .btn:hover { background: var(--bg-btn-hover); border-color: var(--maroon-700); }
  .btn:active { transform: translateY(1px); }
  .card { border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; background: var(--bg-card); color: var(--text-light); }
  .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
  .field { display: flex; flex-direction: column; gap: 6px; }
  label { font-weight: 600; color: var(--text-light); }
  input, textarea { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border-color); font: inherit; background: var(--bg-input); color: var(--text-light); }
  textarea { min-height: 80px; resize: vertical; }
  .row { display: flex; gap: 12px; align-items: center; }
  .notice { padding: 10px 12px; border-radius: 8px; }
  .error { background: #a42b43; color: #fff; border: 1px solid #7a1b2a; }
  .success { background: #5a0f1b; color: #fff; border: 1px solid #7a1b2a; }
  @media (max-width: 640px) {
    .actions { grid-template-columns: 1fr; }
    .grid { grid-template-columns: 1fr; }
  }
  /* Pending badge */
  .badge { position: absolute; top: -6px; right: -6px; background: #dc2626; color: #fff; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }
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
      <a class="btn" href="/maintenance/meso/dashboard.php">Back to Home</a>
      <button class="btn" type="button">Staffs</button>
      <a class="btn" href="/maintenance/meso/workRequest.php" style="position: relative;">
        Work Request
        <?php if ($pendingCount > 0): ?>
          <span class="badge"><?php echo $pendingCount; ?></span>
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
              <div class="row"><img src="../<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" style="height:60px;border:1px solid var(--border-color);border-radius:6px;background:#fff;"></div>
            <?php endif; ?>
            <input id="profile_image" name="profile_image" type="file" accept="image/*">
          </div>
          <div class="field" style="grid-column: 1 / -1;">
            <label for="signature_image">Signature</label>
            <?php if ($signatureImage !== ''): ?>
              <div class="row"><img src="../<?php echo htmlspecialchars($signatureImage); ?>" alt="Signature" style="height:60px;border:1px solid var(--border-color);border-radius:6px;background:#fff;"></div>
            <?php endif; ?>
            <input id="signature_image" name="signature_image" type="file" accept="image/*">
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
