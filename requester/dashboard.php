<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'requester') {
  header('Location: ../main/index.php');
  exit;
}
require_once __DIR__ . '/../supabase_rest.php';

// Resolve display name from DB - prioritize by name field
$displayName = (string)($user['name'] ?? 'Requester');
try {
  $query = ['select' => '*', 'limit' => 1];
  $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
  
  if ($userName !== '') {
    // Try to fetch by name first (with requester role filter)
    $query['name'] = 'eq.' . $userName;
    $query['user_type'] = 'ilike.requester';
  } elseif (isset($user['id']) && $user['id'] !== null && $user['id'] !== '') {
    $query = ['select' => '*', 'limit' => 1];
    $query['id'] = 'eq.' . (string)$user['id'];
  } else {
    $query = null;
  }
  
  if ($query !== null) {
    $rows = supabase_request('GET', 'users', null, $query);
    if (is_array($rows) && count($rows) > 0 && isset($rows[0]['name']) && trim((string)$rows[0]['name']) !== '') {
      $displayName = (string)$rows[0]['name'];
      $_SESSION['user']['name'] = $displayName; // keep session in sync
    }
  }
} catch (Throwable $e) {
  // Fall back to session name
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Requester Dashboard</title>
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
  .actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .btn { padding: 14px 16px; border-radius: 10px; border: 1px solid #e5e5e5; background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .15s ease, border-color .15s ease, transform .1s ease; }
  .btn:hover { background: #fff7f8; border-color: var(--maroon-400); }
  .btn:active { transform: translateY(1px); }
  @media (max-width: 640px) { .actions { grid-template-columns: 1fr; } }
</style>
</head>
<body>
  <header class="topbar">
    <div class="brand">RCC Requester</div>
    <div class="profile">
      
      <div class="name"><?php echo htmlspecialchars($displayName); ?></div>
      <a class="btn" href="../logout.php">Logout</a>
    </div>
  </header>
  <main class="container">
    <section class="actions" aria-label="Requester actions">
      <a class="btn" href="information.php">Information</a>
      <a class="btn" href="requestForm.php">Submit Work Request</a>
      <a class="btn" href="requests.php">REQUEST</a>
      <button class="btn" type="button">Claim Work Request</button>
    </section>
    <?php require_once __DIR__ . '/../components/calendar.php'; ?>
  </main>
</body>
</html>

