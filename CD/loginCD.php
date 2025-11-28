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
      --maroon-900: #3f0a12;
      --maroon-700: #5a0f1b;
      --maroon-500: #8c1d2f;
      --maroon-300: #c04b56;
      --bg: #fbf8f9;
      --muted: #6b7280;
      --panel: #ffffff;
      --edge: #e9e6e7;
    }

    * { box-sizing: border-box; }
    html,body { height: 100%; }
    body {
      margin: 0;
      font-family: "Segoe UI", Roboto, Arial, sans-serif;
      background: linear-gradient(180deg, var(--maroon-700) 0%, var(--maroon-900) 100%);
      /* subtle overlay to keep depth similar to previous design */
      background-image: radial-gradient(800px 320px at 8% 10%, rgba(255,255,255,0.03), transparent),
                        radial-gradient(600px 280px at 92% 90%, rgba(0,0,0,0.04), transparent),
                        linear-gradient(180deg, var(--maroon-700) 0%, var(--maroon-900) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 28px;
    }

    .card {
      width: 100%;
      max-width: 460px;
      background: var(--panel);
      border-radius: 14px;
      padding: 28px;
      box-shadow: 0 18px 32px rgba(23,18,19,0.08), 0 2px 6px rgba(0,0,0,0.04);
      border: 1px solid var(--edge);
      overflow: hidden;
    }

    .card-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 16px;
    }

    .logo {
      flex: 0 0 54px;
      height: 54px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--maroon-700), var(--maroon-500));
      color: #fff;
      display: grid;
      place-items: center;
      font-weight: 700;
      font-size: 18px;
      box-shadow: 0 6px 18px rgba(90,15,27,0.18);
      overflow: hidden;
      padding: 6px;
    }

    .logo img {
      max-width: 100%;
      max-height: 100%;
      display: block;
      object-fit: contain;
      border-radius: 8px;
      background: transparent;
    }

    h1 {
      margin: 0;
      font-size: 20px;
      color: var(--maroon-900);
      letter-spacing: -0.2px;
    }

    .sub { margin: 4px 0 0; color: var(--muted); font-size: 13px; }

    label { display: block; font-weight: 600; color: var(--maroon-700); margin-bottom: 8px; font-size: 13px; }

    input {
      width: 100%;
      padding: 12px 14px;
      border-radius: 10px;
      border: 1px solid var(--edge);
      font: 14px/1.3 "Segoe UI", Roboto, Arial, sans-serif;
      background: #fff;
      transition: box-shadow .12s ease, border-color .12s ease, transform .08s ease;
    }

    input:focus {
      outline: none;
      border-color: var(--maroon-500);
      box-shadow: 0 6px 18px rgba(140,29,47,0.08);
      transform: translateY(-1px);
    }

    .field { margin-bottom: 14px; }

    .btn {
      width: 100%;
      border: none;
      margin-top: 10px;
      padding: 12px 14px;
      border-radius: 10px;
      background: linear-gradient(180deg, var(--maroon-700), var(--maroon-900));
      color: #fff;
      font-weight: 700;
      cursor: pointer;
      font-size: 15px;
      transition: transform .12s ease, box-shadow .12s ease;
      box-shadow: 0 8px 20px rgba(90,15,27,0.12);
    }

    .btn:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(90,15,27,0.14); }

    .error {
      background: #fff4f4;
      color: #7b1515;
      border: 1px solid #f7caca;
      padding: 10px 14px;
      border-radius: 10px;
      margin-bottom: 14px;
      font-size: 14px;
    }

    .back-link {
      display: inline-block;
      margin-top: 14px;
      color: var(--maroon-700);
      text-decoration: none;
      font-weight: 600;
      font-size: 13px;
    }

    .meta-row { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:8px; }

    @media (max-width:420px){
      .card { padding:20px; }
      .logo{flex-basis:48px;height:48px}
      h1{font-size:18px}
    }
  </style>
</head>
<body>
  <section class="card">
    <div class="card-header">
      <div class="logo">
        <img src="../img/logo.png" alt="EVSU logo">
      </div>
      <div class="brand-text">
        <h1>Campus Director</h1>
        <p class="sub">Please sign in using your EVSU account.</p>
      </div>
    </div>
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
    </section>
</body>
</html>

