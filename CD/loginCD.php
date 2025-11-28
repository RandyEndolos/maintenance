<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../supabase_rest.php';

$existingUser = $_SESSION['user'] ?? null;
if ($existingUser && ($existingUser['role'] ?? '') === 'campus_director') {
  header('Location: dashboard.php');
  exit;
}

$error = '';

function fetch_campus_director_row(string $email, string $password): ?array {
  if ($email === '' || $password === '') {
    return null;
  }
  try {
    $rows = supabase_request('GET', 'users', null, [
      'select' => 'id,name,email,password,profile_image,signature_image,department,user_type',
      'email' => 'eq.' . $email,
      'password' => 'eq.' . $password,
      'limit' => 1,
    ]);
    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
      return $rows[0];
    }
  } catch (Throwable $e) {
    // fall back to null
  }
  return null;
}

function is_campus_director_role(?string $role): bool {
  if ($role === null) return false;
  $normalized = strtolower(trim($role));
  return in_array($normalized, ['campus_director', 'campus director', 'director', 'admin'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim((string)($_POST['email'] ?? '')));
  $password = (string)($_POST['password'] ?? '');
  if ($email === '' || $password === '') {
    $error = 'Email and password are required.';
  } else {
    $userRow = fetch_campus_director_row($email, $password);
    if (!$userRow) {
      $error = 'Account not found.';
    } elseif (!is_campus_director_role($userRow['user_type'] ?? null)) {
      $error = 'Your account does not have Campus Director access.';
    } else {
      $_SESSION['user'] = [
        'role' => 'campus_director',
        'id' => $userRow['id'] ?? null,
        'name' => $userRow['name'] ?? 'Campus Director',
        'email' => $userRow['email'] ?? $email,
        'department' => $userRow['department'] ?? '',
        'avatar' => $userRow['profile_image'] ?? '',
        'signature_image' => $userRow['signature_image'] ?? '',
      ];
      header('Location: dashboard.php');
      exit;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Campus Director Login</title>
  <style>
    :root {
      --maroon-700: #5a0f1b;
      --maroon-500: #8c1d2f;
      --gray-50: #f9f6f7;
      --gray-300: #e5e7eb;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Segoe UI", Roboto, Arial, sans-serif;
      background: var(--gray-50);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: #fff;
      border-radius: 16px;
      padding: 32px;
      box-shadow: 0 25px 40px rgba(0,0,0,0.09);
      border: 1px solid var(--gray-300);
    }
    h1 {
      margin: 0 0 8px;
      font-size: 26px;
      color: var(--maroon-700);
    }
    p { margin: 0 0 20px; color: #4b5563; }
    label { display: block; font-weight: 600; color: var(--maroon-700); margin-bottom: 6px; }
    input {
      width: 100%;
      padding: 12px 14px;
      border-radius: 10px;
      border: 1px solid var(--gray-300);
      font: inherit;
      background: #fff;
    }
    .btn {
      width: 100%;
      border: none;
      margin-top: 18px;
      padding: 12px 14px;
      border-radius: 10px;
      background: var(--maroon-700);
      color: #fff;
      font-weight: 600;
      cursor: pointer;
      font-size: 16px;
      transition: background .15s ease;
    }
    .btn:hover { background: var(--maroon-500); }
    .error {
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
      padding: 10px 14px;
      border-radius: 8px;
      margin-bottom: 16px;
      font-size: 14px;
    }
    .field { margin-bottom: 16px; }
    .back-link {
      display: inline-block;
      margin-top: 16px;
      color: var(--maroon-700);
      text-decoration: none;
      font-weight: 600;
    }
  </style>
</head>
<body>
  <section class="card">
    <h1>Campus Director</h1>
    <p>Please sign in using your EVSU account.</p>
    <?php if ($error !== ''): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="post" novalidate>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?php echo htmlspecialchars((string)($_POST['email'] ?? '')); ?>" required>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>
      </div>
      <button class="btn" type="submit">Sign In</button>
    </form>
    <a class="back-link" href="../main/index.php">&larr; Back to main portal</a>
  </section>
</body>
</html>

