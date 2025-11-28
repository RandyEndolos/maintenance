<?php
// Simple role-based login router.
// NOTE: This demo validates only presence of required fields.
// Integrate with your real auth (e.g., database/supabase) as needed.

session_start();
require_once __DIR__ . '/supabase_rest.php';

function fetch_user_name_from_db(string $role, string $email = '', string $name = ''): string {
  try {
    $query = ['select' => 'name', 'limit' => 1];
    
    if ($role === 'staff' && $name !== '') {
      // For staff, search by name
      $query['name'] = 'eq.' . $name;
      $query['user_type'] = 'ilike.staff';
    } elseif (($role === 'requester' || $role === 'admin') && $email !== '') {
      // For requester/admin, search by email
      $query['email'] = 'eq.' . $email;
      $userType = $role === 'admin' ? 'admin' : 'requester';
      $query['user_type'] = 'ilike.' . $userType;
    } else {
      return '';
    }
    
    $rows = supabase_request('GET', 'users', null, $query);
    if (is_array($rows) && count($rows) > 0 && isset($rows[0]['name']) && trim((string)$rows[0]['name']) !== '') {
      return trim((string)$rows[0]['name']);
    }
  } catch (Throwable $e) {
    // Fall back to empty string
  }
  return '';
}

function make_display_name(string $role, array $src): string {
  $email = trim((string)($src['email'] ?? ''));
  $name = trim((string)($src['name'] ?? ''));
  
  // Try to fetch from database first
  $dbName = fetch_user_name_from_db($role, $email, $name);
  if ($dbName !== '') {
    return $dbName;
  }
  
  // Fallback: use name if provided
  if ($name !== '') {
    return $name;
  }
  
  // Last resort: extract from email (but this should rarely happen)
  if ($email !== '' && strpos($email, '@') !== false) {
    $local = substr($email, 0, strpos($email, '@'));
    return $local !== '' ? $local : $email;
  }
  
  return 'User';
}

function default_avatar_path(): string {
  $candidates = [
    'uploads/download-0c1e78be.jpg',
    'uploads/download-208dafbb.jpg',
    'uploads/download-6ae782bb.jpg',
    'uploads/download-a9e84bea.jpg',
  ];
  foreach ($candidates as $p) {
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . $p)) return $p;
  }
  return '';
}

function redirect_with_error(string $role, string $message): void {
  $params = http_build_query([
    'role' => $role,
    'error' => $message,
  ]);
  header('Location: main/index.php?' . $params);
  exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Location: main/index.php');
  exit;
}

$role = isset($_POST['role']) ? strtolower(trim((string)$_POST['role'])) : '';

switch ($role) {
  case 'requester': {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
      redirect_with_error('requester', 'Email and password are required.');
    }
    // TODO: Replace this with real authentication logic.
    $_SESSION['user'] = [
      'role' => 'requester',
      'name' => make_display_name('requester', $_POST),
      'email' => $email,
      'avatar' => default_avatar_path(),
    ];
    header('Location: requester/dashboard.php');
    exit;
  }
  case 'staff': {
    $name = trim((string)($_POST['name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || $password === '') {
      redirect_with_error('staff', 'Name and password are required.');
    }
    // TODO: Replace this with real authentication logic.
    $_SESSION['user'] = [
      'role' => 'staff',
      'name' => make_display_name('staff', $_POST),
      'email' => null,
      'avatar' => default_avatar_path(),
    ];
    header('Location: staff/dashboard.php');
    exit;
  }
  case 'admin': {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
      redirect_with_error('admin', 'Email and password are required.');
    }
    // TODO: Replace this with real authentication logic.
    // Assuming Admin dashboard is under meso/ per project layout.
    $_SESSION['user'] = [
      'role' => 'admin',
      'name' => make_display_name('admin', $_POST),
      'email' => $email,
      'avatar' => default_avatar_path(),
    ];
    header('Location: meso/dashboard.php');
    exit;
  }
  default: {
    redirect_with_error('requester', 'Invalid role.');
  }
}


