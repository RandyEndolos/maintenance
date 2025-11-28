<?php
declare(strict_types=1);

// Simple reusable PDO connector for Supabase Postgres
// Configure via environment variables (either URL or discrete fields):
//   SUPABASE_DB_URL    e.g. postgresql://user:pass@host:5432/dbname
//   SUPABASE_DB_HOST   e.g. db.xxxxx.supabase.co
//   SUPABASE_DB_PORT   default 5432
//   SUPABASE_DB_NAME   e.g. postgres
//   SUPABASE_DB_USER   e.g. postgres
//   SUPABASE_DB_PASS   your database password
//   SUPABASE_DB_SSLMODE default 'require' (recommended)

final class DB
{
  private static $pdo = null; // PDO|null (untyped for wider PHP compatibility)

  private static function fromUrl(string $url): array
  {
    // First try PHP's parser
    $parts = parse_url($url);
    $needFallback = false;
    if ($parts === false || !isset($parts['scheme'])) {
      $needFallback = true;
    }
    // Fallback if password missing but there are multiple '@' in URL (likely unencoded '@' in password)
    if (!$needFallback) {
      $atCount = substr_count($url, '@');
      if ($atCount > 1 || (!isset($parts['pass']) && $atCount >= 1)) {
        $needFallback = true;
      }
    }

    if (!$needFallback && isset($parts['host'])) {
      $user = $parts['user'] ?? '';
      $pass = $parts['pass'] ?? '';
      if (strlen($pass) > 1 && $pass[0] === '[' && substr($pass, -1) === ']') {
        $pass = substr($pass, 1, -1);
      }
      $host = $parts['host'];
      $port = (string)($parts['port'] ?? '5432');
      $dbname = isset($parts['path']) ? ltrim((string)$parts['path'], '/') : 'postgres';
      $sslmode = getenv('SUPABASE_DB_SSLMODE') ?: 'require';
      if ($user === '' || $pass === '') {
        $needFallback = true;
      } else {
        return [
          'host' => $host,
          'port' => $port,
          'dbname' => $dbname,
          'user' => $user,
          'pass' => $pass,
          'sslmode' => $sslmode,
        ];
      }
    }

    // Fallback parser to tolerate passwords containing '@' and stray spaces after ':'
    // Example: postgresql://postgres: MyP@ss@host:5432/db
    $normalized = trim($url);
    $prefixes = ['postgresql://', 'postgres://'];
    $withoutScheme = $normalized;
    foreach ($prefixes as $p) {
      if (stripos($normalized, $p) === 0) {
        $withoutScheme = substr($normalized, strlen($p));
        break;
      }
    }
    // Split at first '/' to separate authority and db name
    $slashPos = strpos($withoutScheme, '/');
    $authority = $slashPos === false ? $withoutScheme : substr($withoutScheme, 0, $slashPos);
    $dbname = $slashPos === false ? 'postgres' : substr($withoutScheme, $slashPos + 1);

    // Separate auth and host by the LAST '@' to allow '@' in password
    $lastAt = strrpos($authority, '@');
    if ($lastAt === false) {
      throw new \RuntimeException('Invalid SUPABASE_DB_URL: missing host segment.');
    }
    $authPart = substr($authority, 0, $lastAt);
    $hostPart = substr($authority, $lastAt + 1);

    // Auth part: user:pass (password may contain ':' after the first one, but commonly only the first ':' separates user and pass)
    $colonPos = strpos($authPart, ':');
    if ($colonPos === false) {
      throw new \RuntimeException('Invalid SUPABASE_DB_URL: missing user:password.');
    }
    $user = substr($authPart, 0, $colonPos);
    $pass = substr($authPart, $colonPos + 1);
    // Trim a single leading space if user accidentally typed one after ':'
    if (strlen($pass) > 0 && $pass[0] === ' ') {
      $pass = ltrim($pass);
    }

    // Host part: host:port
    $host = $hostPart;
    $port = '5432';
    $hpColon = strrpos($hostPart, ':');
    if ($hpColon !== false) {
      $host = substr($hostPart, 0, $hpColon);
      $port = substr($hostPart, $hpColon + 1);
    }

    $user = (string)$user;
    $pass = (string)$pass;
    $host = (string)$host;
    $port = $port === '' ? '5432' : (string)$port;
    $dbname = $dbname === '' ? 'postgres' : $dbname;
    $sslmode = getenv('SUPABASE_DB_SSLMODE') ?: 'require';

    if ($user === '' || $pass === '' || $host === '') {
      throw new \RuntimeException('Invalid SUPABASE_DB_URL: missing credentials or host.');
    }

    return [
      'host' => $host,
      'port' => $port,
      'dbname' => $dbname,
      'user' => $user,
      'pass' => $pass,
      'sslmode' => $sslmode,
    ];
  }

  public static function connection(): \PDO
  {
    if (self::$pdo instanceof \PDO) {
      return self::$pdo;
    }

    $url = getenv('SUPABASE_DB_URL') ?: '';
    if ($url !== '') {
      $cfg = self::fromUrl($url);
    } else {
      $cfg = [
        'host' => getenv('SUPABASE_DB_HOST') ?: '',
        'port' => getenv('SUPABASE_DB_PORT') ?: '5432',
        'dbname' => getenv('SUPABASE_DB_NAME') ?: 'postgres',
        'user' => getenv('SUPABASE_DB_USER') ?: '',
        'pass' => getenv('SUPABASE_DB_PASS') ?: '',
        'sslmode' => getenv('SUPABASE_DB_SSLMODE') ?: 'require',
      ];
    }

    if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
      throw new \RuntimeException('Supabase DB credentials are not fully set. Provide SUPABASE_DB_URL or SUPABASE_DB_HOST/USER/PASS.');
    }

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $cfg['host'], $cfg['port'], $cfg['dbname'], $cfg['sslmode']);

    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    self::$pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], $options);
    return self::$pdo;
  }
}

// Convenience function if you prefer a functional style
function db(): \PDO {
  return DB::connection();
}

// Example usage in other PHP files:
// require_once __DIR__ . '/db_connector.php';
// putenv('SUPABASE_DB_URL=postgresql://user:pass@host:5432/postgres'); // or configure in Apache/Environment
// $pdo = db();
// $rows = $pdo->query('SELECT 1')->fetchAll();
?>


