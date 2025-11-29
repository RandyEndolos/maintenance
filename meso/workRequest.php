<?php
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
    header('Location: ../main/index.php');
    exit;
}
require_once __DIR__ . '/../supabase_rest.php';
require_once __DIR__ . '/../helpers/work_request_deadlines.php';
$errors = [];
$success = '';
// Fetch current admin info (name + signature) - prioritize by name field
$displayName = (string)($user['name'] ?? 'Admin');
$adminEmail = (string)($user['email'] ?? '');
$adminId = isset($user['id']) ? (string)$user['id'] : '';
$adminSignature = (string)($user['signature_image'] ?? '');
try {
    $query = ['select' => '*', 'limit' => 1];
    $userName = isset($user['name']) && trim((string)$user['name']) !== '' ? trim((string)$user['name']) : '';
    
    if ($userName !== '') {
        // Try to fetch by name first (with admin role filter)
        $query['name'] = 'eq.' . $userName;
        $query['user_type'] = 'ilike.admin';
    } elseif ($adminId !== '') { 
        $query = ['select' => '*', 'limit' => 1];
        $query['id'] = 'eq.' . $adminId; 
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
            if (isset($rows[0]['signature_image']) && trim((string)$rows[0]['signature_image']) !== '') { 
                $adminSignature = (string)$rows[0]['signature_image'];
            }
        }
    }
} catch (Throwable $e) {
    // Fall back to session name
}
$adminName = $displayName; // Keep for backward compatibility
// Fetch staff directory with areas of work
$staffDirectory = [];
try {
    $staffDirectory = supabase_request('GET', 'users', null, [
        'select' => 'id,name,area_of_work',
        'user_type' => 'ilike.staff',
        'order' => 'name.asc'
    ]);
    if (!is_array($staffDirectory)) { $staffDirectory = []; }
} catch (Throwable $e) {
    $staffDirectory = [];
}
// Handle assign task submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'assign') {
        $id = (string)($_POST['id'] ?? '');
        $status = trim((string)($_POST['status'] ?? ''));
        // availability yes/no -> boolean
        $availabilityRaw = (string)($_POST['availability_of_materials'] ?? '');
        $availability = null;
        if ($availabilityRaw !== '') { $availability = strtolower($availabilityRaw) === 'yes'; }
        // staff list (array or single)
        $staffAssigned = '';
        if (isset($_POST['staff_assigned']) && is_array($_POST['staff_assigned'])) {
            $clean = array_values(array_filter(array_map(function($v){ return trim((string)$v); }, $_POST['staff_assigned']), function($v){ return $v !== ''; }));
            $staffAssigned = implode(', ', $clean);
        } else {
            $staffAssigned = trim((string)($_POST['staff_assigned'] ?? ''));
        }
        if ($id === '') {
            $errors[] = 'Missing request id.';
        }
        // Staff may be optional, but recommended
        if (!$errors) {
            try {
                $update = [];
                if ($staffAssigned !== '') { $update['staff_assigned'] = $staffAssigned; }
                if (!is_null($availability)) { $update['availability_of_materials'] = $availability; }
                if ($adminSignature !== '') { $update['meso_signature'] = $adminSignature; }
                // Default status to Waiting for Staff if not explicitly set
                $update['status'] = $status !== '' ? $status : 'Waiting for Staff';
                supabase_request('PATCH', 'work_request', $update, ['id' => 'eq.' . $id]);
                $success = 'Task assigned successfully.';
            } catch (Throwable $e) {
                $errors[] = 'Failed to assign task.';
            }
        }
    }
}
// Fetch all work requests (oldest first)
$requests = [];
try {
    $requests = supabase_request('GET', 'work_request', null, [
        'select' => '*',
        'order' => 'date_requested.asc'
    ]);
    if (!is_array($requests)) { $requests = []; }
} catch (Throwable $e) {
    $requests = [];
}
// Helper badge
function wr_status_badge(string $status): string {
    $statusLower = strtolower($status);
    $cls = 'status-badge ';
    if ($statusLower === 'pending') $cls .= 'status-pending';
  elseif ($statusLower === 'in progress' || $statusLower === 'in-progress' || $statusLower === 'waiting for staff' || $statusLower === 'for pickup/confirmation' || $statusLower === 'waiting for pickup/confirmation' || $statusLower === 'waiting for pick up/confirmation') $cls .= 'status-progress';
    elseif ($statusLower === 'completed' || $statusLower === 'done') $cls .= 'status-completed';
    elseif ($statusLower === 'cancelled' || $statusLower === 'canceled') $cls .= 'status-cancelled';
    else $cls .= 'status-default';
    return '<span class="' . htmlspecialchars($cls) . '">' . htmlspecialchars($status) . '</span>';
}
function fmt_date(?string $dateStr): string {
    if ($dateStr === null || trim($dateStr) === '') return 'N/A';
    try {
        $dt = new DateTime($dateStr);
        return $dt->format('M d, Y');
    } catch (Throwable $e) { return htmlspecialchars($dateStr); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Work Requests</title>
<style>
    :root {
        --maroon-700: #5a0f1b;
        --maroon-600: #7a1b2a;
        --maroon-400: #a42b43;
        --offwhite: #f9f6f7;
        --text: #222;
        --muted: #6b7280;
        --border: #e5e7eb;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #fff; color: var(--text); }
    .topbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #eee; background: var(--offwhite); }
    .brand { font-weight: 700; color: var(--maroon-700); }
    .profile { display: flex; align-items: center; gap: 10px; }
    .name { font-weight: 600; color: var(--maroon-700); }
    .container { max-width: 1200px; margin: 20px auto; padding: 0 16px; }
    .actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    .btn { display: inline-block; text-decoration: none; text-align: center; padding: 10px 14px; border-radius: 10px; border: 1px solid #e5e5e5; background: #fff; cursor: pointer; font-weight: 600; color: var(--maroon-700); transition: background .15s ease, border-color .15s ease, transform .1s ease; }
    .btn:hover { background: #fff7f8; border-color: var(--maroon-400); }
    .btn:active { transform: translateY(1px); }
    .page-title { margin: 0 0 6px; color: var(--maroon-700); font-size: 22px; font-weight: 700; }
    .subtext { margin: 0 0 16px; color: var(--muted); font-size: 14px; }
    .request-list { display: flex; flex-direction: column; gap: 14px; }
    .card { border: 1px solid var(--border); border-radius: 12px; padding: 16px; background: #fff; }
    .request-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
    .request-id { font-weight: 700; color: var(--maroon-700); }
    .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .detail { display: flex; flex-direction: column; gap: 4px; }
    .label { font-size: 12px; color: var(--muted); font-weight: 600; }
    .value { font-size: 14px; color: var(--text); }
    .deadline-flag { font-weight: 600; }
    .deadline-flag.overdue { color: #b91c1c; }
    .deadline-flag.due_soon { color: #92400e; }
    .deadline-flag.on_track { color: #047857; }
    .status-badge { display: inline-block; padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-progress { background: #dbeafe; color: #1e40af; }
    .status-completed { background: #d1fae5; color: #065f46; }
    .status-cancelled { background: #fee2e2; color: #991b1b; }
    .status-default { background: #f3f4f6; color: #374151; }
    .assign-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .input { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); font: inherit; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .notice { padding: 10px 12px; border-radius: 8px; }
    .error { background: #fff1f2; color: #7a1b2a; border: 1px solid #ffd5da; }
    .success { background: #ecfeff; color: #0b6b74; border: 1px solid #cffafe; }
    @media (max-width: 800px) { .grid { grid-template-columns: 1fr; } .actions { grid-template-columns: 1fr; } }
    .actions-bar { display: flex; gap: 8px; }
    .btn.small { padding: 6px 10px; border-radius: 8px; font-size: 12px; }
    .btn.primary { background: var(--maroon-600); color: #fff; border-color: var(--maroon-600); }
    .btn.primary:hover { background: var(--maroon-700); border-color: var(--maroon-700); }
    .inline-form { margin-top: 10px; padding: 10px; border: 1px dashed var(--border); border-radius: 8px; background: #fafafa; }
    .muted { color: var(--muted); font-size: 12px; }
    .img-thumb { max-width: 180px; max-height: 120px; border-radius: 8px; border: 1px solid var(--border); }
    .hr { height: 1px; background: var(--border); margin: 10px 0; border: 0; }
    .actions-bar { position: relative; z-index: 2; }
    .actions-bar .btn { pointer-events: auto; }
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
            <a class="btn"  href="/maintenance/meso/staff.php">Staffs</a>
            <button class="btn" type="button">Reports</button>
        </section>
        <?php if ($errors): ?>
            <div class="notice error"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
        <?php elseif ($success !== ''): ?>
            <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <h2 class="page-title">Work Requests</h2>
        <p class="subtext">View requests and assign tasks to staff.</p>
        <?php if (count($requests) === 0): ?>
            <div class="card" style="text-align:center; color: var(--muted);">No work requests found.</div>
        <?php else: ?>
            <div class="request-list">
                <?php foreach ($requests as $req): ?>
                    <?php $deadlineMeta = wr_enrich_deadline($req); ?>
                    <div class="card">
                        <div class="request-header">
                            <div class="request-id">Request #<?php echo htmlspecialchars((string)($req['id'] ?? '')); ?></div>
                            <?php echo wr_status_badge((string)($req['status'] ?? 'Pending')); ?>
                        </div>
                        <div class="grid">
                            <div class="detail">
                                <span class="label">Requester</span>
                                <span class="value"><?php echo htmlspecialchars((string)($req['requesters_name'] ?? 'N/A')); ?></span>
                            </div>
                            <div class="detail">
                                <span class="label">Department</span>
                                <span class="value"><?php echo htmlspecialchars((string)($req['department'] ?? 'N/A')); ?></span>
                            </div>
                            <div class="detail">
                                <span class="label">Date Requested</span>
                                <span class="value"><?php echo fmt_date($req['date_requested'] ?? null); ?></span>
                            </div>
                            <div class="detail">
                                <span class="label">Type</span>
                                <span class="value"><?php echo htmlspecialchars((string)($req['type_of_request'] ?? 'N/A')); ?></span>
                            </div>
                            <div class="detail">
                                <span class="label">Location</span>
                                <span class="value"><?php echo htmlspecialchars((string)($req['location'] ?? 'N/A')); ?></span>
                            </div>
                            <div class="detail">
                                <span class="label">Personnel Needed</span>
                                <span class="value"><?php echo htmlspecialchars((string)($req['no_of_personnel_needed'] ?? 'N/A')); ?></span>
                            </div>
                            <div class="detail" style="grid-column: 1 / -1;">
                                <span class="label">Description</span>
                                <span class="value"><?php echo nl2br(htmlspecialchars((string)($req['description_of_work'] ?? ''))); ?></span>
                            </div>
                            <?php if (!empty($req['image_of_work'])): ?>
                                <div class="detail" style="grid-column: 1 / -1;">
                                    <span class="label">Image of Work</span>
                                    <div style="margin-top:6px;"><img class="img-thumb" src="../<?php echo htmlspecialchars((string)$req['image_of_work']); ?>" alt="Work image"></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($req['staff_assigned'])): ?>
                                <div class="detail">
                                    <span class="label">Staff Assigned</span>
                                    <span class="value"><?php echo htmlspecialchars((string)$req['staff_assigned']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($deadlineMeta['deadline_display'])): ?>
                                <div class="detail">
                                    <span class="label">Target Deadline</span>
                                    <span class="value deadline-flag <?php echo htmlspecialchars($deadlineMeta['deadline_state']); ?>">
                                        <?php echo htmlspecialchars((string)$deadlineMeta['deadline_display']); ?>
                                        <small style="display:block; color: var(--muted); font-weight:400;"><?php echo htmlspecialchars((string)$deadlineMeta['human_delta']); ?></small>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <hr class="hr">
                        <?php 
                            $currentStatus = strtolower((string)($req['status'] ?? 'pending'));
                            $isCompleted = ($currentStatus === 'completed' || $currentStatus === 'done');
                        ?>
                        <?php if (!$isCompleted): ?>
                        <div class="actions-bar">
                            <button class="btn small primary" type="button" data-assign-id="<?php echo htmlspecialchars((string)$req['id']); ?>">Assign Task</button>
                        </div>
                        <?php endif; ?>
                        <div id="assign-<?php echo htmlspecialchars((string)$req['id']); ?>" class="inline-form" style="display:none;">
                            <form method="post">
                                <input type="hidden" name="action" value="assign">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$req['id']); ?>">
                                <div class="grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                                    <div class="field">
                                        <span class="label">Availability of Materials</span>
                                        <?php $avail = isset($req['availability_of_materials']) ? (bool)$req['availability_of_materials'] : null; ?>
                                        <div class="row">
                                            <label class="row"><input type="radio" name="availability_of_materials" value="Yes" <?php echo $avail===true?'checked':''; ?>> <span>Yes</span></label>
                                            <label class="row"><input type="radio" name="availability_of_materials" value="No" <?php echo $avail===false?'checked':''; ?>> <span>No</span></label>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <span class="label">Admin</span>
                                        <input class="input" type="text" value="<?php echo htmlspecialchars($adminName); ?>" readonly>
                                        <?php if ($adminSignature !== ''): ?>
                                            <div class="muted">Signature will be attached automatically.</div>
                                        <?php else: ?>
                                            <div class="muted">No signature on file.</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="field" style="grid-column: 1 / -1;">
                                        <span class="label">Staff Assignment (<?php echo htmlspecialchars((string)($req['no_of_personnel_needed'] ?? '1')); ?> needed)</span>
                                        <?php
                                            $needed = (int)($req['no_of_personnel_needed'] ?? 1);
                                            if ($needed <= 0) { $needed = 1; }
                                            $existing = isset($req['staff_assigned']) ? array_map('trim', explode(',', (string)$req['staff_assigned'])) : [];
                                            $typeStr = strtolower((string)($req['type_of_request'] ?? ''));
                                            $matchAreas = [];
                                            if ($typeStr !== '') {
                                                // very simple mapping: if area_of_work appears in type string
                                                foreach ($staffDirectory as $s) {
                                                    $area = strtolower((string)($s['area_of_work'] ?? ''));
                                                    if ($area !== '' && strpos($typeStr, $area) !== false) {
                                                        $matchAreas[] = (int)$s['id'];
                                                    }
                                                }
                                            }
                                            for ($i=0; $i<$needed; $i++) {
                                                $prefill = isset($existing[$i]) ? $existing[$i] : '';
                                        ?>
                                            <select class="input" name="staff_assigned[]">
                                                <option value="">-- Select staff #<?php echo $i+1; ?> --</option>
                                                <?php
                                                    // First show matching area staff
                                                    if (!empty($matchAreas)) {
                                                        echo '<option disabled>— Matching area —</option>';
                                                        foreach ($staffDirectory as $s) {
                                                            if (!in_array((int)$s['id'], $matchAreas, true)) continue;
                                                            $name = (string)($s['name'] ?? '');
                                                            $selected = $prefill !== '' && strtolower($prefill) === strtolower($name) ? 'selected' : '';
                                                            echo '<option value="' . htmlspecialchars($name) . '" ' . $selected . '>' . htmlspecialchars($name) . ' (' . htmlspecialchars((string)($s['area_of_work'] ?? '')) . ')</option>';
                                                        }
                                                    }
                                                    // Then all staff
                                                    if (count($staffDirectory) > 0) {
                                                        echo '<option disabled>— All staff —</option>';
                                                    }
                                                    foreach ($staffDirectory as $s) {
                                                        $name = (string)($s['name'] ?? '');
                                                        $selected = $prefill !== '' && strtolower($prefill) === strtolower($name) ? 'selected' : '';
                                                        echo '<option value="' . htmlspecialchars($name) . '" ' . $selected . '>' . htmlspecialchars($name) . ' (' . htmlspecialchars((string)($s['area_of_work'] ?? '')) . ')</option>';
                                                    }
                                                ?>
                                            </select>
                                        <?php } ?>
                                        <div class="muted">Staff are listed with their areas of work. Matching areas are shown first.</div>
                                    </div>
                                    <div class="field">
                                        <span class="label">Status</span>
                                        <?php $st = strtolower((string)($req['status'] ?? 'Pending')); ?>
                                        <select class="input" name="status">
                                            <option value="">Waiting for Staff</option>
                                            <option value="Pending" <?php echo $st==='pending'?'selected':''; ?>>Pending</option>
                                            <option value="Waiting for Staff" <?php echo $st==='waiting for staff'?'selected':''; ?>>Waiting for Staff</option>
                                            <option value="In Progress" <?php echo ($st==='in progress' || $st==='in-progress')?'selected':''; ?>>In Progress</option>
                                            <option value="Completed" <?php echo $st==='completed'?'selected':''; ?>>Completed</option>
                                            <option value="Cancelled" <?php echo ($st==='cancelled'||$st==='canceled')?'selected':''; ?>>Cancelled</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="actions-bar" style="margin-top:8px;">
                                    <button class="btn small primary" type="submit">Save</button>
                                    <button class="btn small" type="button" data-cancel-id="<?php echo htmlspecialchars((string)$req['id']); ?>">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <script>
        function toggleAssign(id) {
            var el = document.getElementById('assign-' + id);
            if (!el) return;
            el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }

        document.querySelectorAll('[data-assign-id]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var id = this.getAttribute('data-assign-id');
                toggleAssign(id);
            });
        });

        document.querySelectorAll('[data-cancel-id]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var id = this.getAttribute('data-cancel-id');
                toggleAssign(id);
            });
        });
    </script>
</body>
</html>
