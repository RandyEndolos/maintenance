<?php
declare(strict_types=1);

// Minimal Supabase REST helper for PHP
// Usage:
//   require_once __DIR__ . '/supabase_rest.php';
//   $result = supabase_insert('users', ['name' => 'Jane']);

 function supabase_base_url(): string {
   $url = getenv('SUPABASE_URL') ?: '';
   if ($url !== '') return rtrim($url, '/');
   // Fallback project URL (replace with env in production)
   return 'https://kmrqgqodgwwseaotbsvt.supabase.co';
 }

function supabase_anon_key(): string {
  $key = getenv('SUPABASE_ANON_KEY') ?: '';
  if ($key !== '') return $key;
   // Fallback anon key from your snippet (replace or set env for production)
   return 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImttcnFncW9kZ3d3c2Vhb3Ric3Z0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MTY1OTYsImV4cCI6MjA3NzI5MjU5Nn0._HFdvA-wDeMkU6TOij0IIxsbJCSnoOEtLP8yKFUDAnE';
}

function supabase_request(string $method, string $path, array $body = null, array $query = []): array {
  $base = supabase_base_url();
  $key = supabase_anon_key();
  $url = $base . '/rest/v1/' . ltrim($path, '/');
  if (!empty($query)) {
    $url .= '?' . http_build_query($query);
  }

  $headers = [
    'apikey: ' . $key,
    'Authorization: Bearer ' . $key,
    'Content-Type: application/json',
    'Accept-Profile: public',
  ];
  // For inserts/updates we often want the inserted row back
  if (in_array(strtoupper($method), ['POST','PATCH','PUT'], true)) {
    $headers[] = 'Prefer: return=representation';
    // In multi-schema databases, Content-Profile tells Supabase which schema to write to
    $headers[] = 'Content-Profile: public';
  }

  $ch = curl_init($url);
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => strtoupper($method),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 20,
  ];
  if (!is_null($body)) {
    $opts[CURLOPT_POSTFIELDS] = json_encode($body);
  }
  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($resp === false) {
    throw new \RuntimeException('Supabase REST error: ' . $err);
  }
  if ($status >= 400) {
    throw new \RuntimeException('Supabase REST HTTP ' . $status . ': ' . $resp);
  }
  $data = json_decode($resp, true);
  if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    throw new \RuntimeException('Supabase REST invalid JSON response');
  }
  return $data ?? [];
}

function supabase_insert(string $table, array $row): array {
  // Supabase expects an array of rows for bulk insert; single is allowed too
  $result = supabase_request('POST', $table, [$row]);
  // Return the first inserted row if available
  return is_array($result) && isset($result[0]) ? $result[0] : $result;
}

?>


