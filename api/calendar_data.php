<?php
session_start();
require_once __DIR__ . '/../supabase_rest.php';

header('Content-Type: application/json');

// Fetch all staff users
$staff = [];
try {
    $staff = supabase_request('GET', 'users', null, [
        'select' => 'id,name,email,user_type,contact_number,department,area_of_work,profile_image',
        'user_type' => 'ilike.staff',
        'order' => 'name.asc'
    ]);
    if (!is_array($staff)) {
        $staff = [];
    }
} catch (Throwable $e) {
    $staff = [];
}

// Fetch all work requests with date ranges
$workRequests = [];
try {
    $workRequests = supabase_request('GET', 'work_request', null, [
        'select' => 'id,staff_assigned,description_of_work,location,type_of_request,requesters_name,time_duration,date_start,date_finish,time_start,time_finish,status',
        'order' => 'date_start.asc'
    ]);
    if (!is_array($workRequests)) {
        $workRequests = [];
    }
} catch (Throwable $e) {
    $workRequests = [];
}

// Build calendar data structure
$calendarData = [
    'staff' => [],
    'tasks' => [],
    'availableStaff' => []
];

// Map staff by name for quick lookup (case-insensitive)
// Also create a normalized name map for matching
$staffMap = [];
$staffNameMap = []; // Normalized (lowercase) name to actual name mapping

foreach ($staff as $s) {
    // Double-check user_type is staff (case-insensitive)
    $userType = strtolower(trim((string)($s['user_type'] ?? '')));
    if ($userType !== 'staff') {
        continue; // Skip if not staff
    }
    
    $name = trim((string)($s['name'] ?? ''));
    if ($name === '') {
        continue; // Skip if no name
    }
    
    $normalizedName = strtolower($name);
    
    $staffMap[$name] = [
        'id' => (string)($s['id'] ?? ''),
        'name' => $name,
        'email' => (string)($s['email'] ?? ''),
        'contact_number' => (string)($s['contact_number'] ?? ''),
        'department' => (string)($s['department'] ?? ''),
        'area_of_work' => (string)($s['area_of_work'] ?? ''),
        'profile_image' => (string)($s['profile_image'] ?? ''),
        'user_type' => 'staff', // Ensure it's set
        'tasks' => []
    ];
    
    // Store normalized name mapping for case-insensitive lookup
    $staffNameMap[$normalizedName] = $name;
}

// Process work requests and assign to staff
$today = new DateTime();
$today->setTime(0, 0, 0);

foreach ($workRequests as $wr) {
    $staffAssigned = trim((string)($wr['staff_assigned'] ?? ''));
    if ($staffAssigned === '') {
        continue;
    }
    
    // Parse comma-separated staff names
    $assignedStaffNames = array_map('trim', explode(',', $staffAssigned));
    $assignedStaffNames = array_filter($assignedStaffNames, function($n) { return $n !== ''; });
    
    $dateStart = null;
    $dateFinish = null;
    
    try {
        if (!empty($wr['date_start'])) {
            $dateStart = new DateTime($wr['date_start']);
            $dateStart->setTime(0, 0, 0);
        }
        if (!empty($wr['date_finish'])) {
            $dateFinish = new DateTime($wr['date_finish']);
            $dateFinish->setTime(0, 0, 0);
        }
    } catch (Throwable $e) {
        continue;
    }
    
    if ($dateStart === null) {
        continue;
    }
    
    // If no finish date, use start date
    if ($dateFinish === null) {
        $dateFinish = clone $dateStart;
    }
    
    // Ensure finish date is not before start date
    if ($dateFinish < $dateStart) {
        $dateFinish = clone $dateStart;
    }
    
    $status = strtolower(trim((string)($wr['status'] ?? 'pending')));
    
    // Skip completed tasks - only show ongoing tasks
    if (in_array($status, ['completed', 'done'])) {
        continue;
    }
    
    // Create task object
    // date_start represents the acceptance date (when staff accepted the task)
    $task = [
        'id' => (string)($wr['id'] ?? ''),
        'description' => (string)($wr['description_of_work'] ?? ''),
        'type' => (string)($wr['type_of_request'] ?? ''),
        'requester' => (string)($wr['requesters_name'] ?? ''),
        'location' => (string)($wr['location'] ?? ''),
        'duration' => (string)($wr['time_duration'] ?? ''),
        'time_start' => (string)($wr['time_start'] ?? ''),
        'time_finish' => (string)($wr['time_finish'] ?? ''),
        'date_start' => $dateStart->format('Y-m-d'), // This is the acceptance date
        'date_finish' => $dateFinish->format('Y-m-d'),
        'status' => $status,
        'staff' => []
    ];
    
    // Assign task to each staff member and include staff details
    // Use case-insensitive matching
    foreach ($assignedStaffNames as $staffName) {
        $normalizedName = strtolower(trim($staffName));
        $matchedName = null;
        
        // Try exact match first
        if (isset($staffMap[$staffName])) {
            $matchedName = $staffName;
        } 
        // Try case-insensitive match
        elseif (isset($staffNameMap[$normalizedName])) {
            $matchedName = $staffNameMap[$normalizedName];
        }
        
        if ($matchedName !== null && isset($staffMap[$matchedName])) {
            $staffMember = $staffMap[$matchedName];
            $staffInfo = [
                'name' => $staffMember['name'],
                'id' => $staffMember['id'],
                'email' => $staffMember['email'],
                'contact_number' => $staffMember['contact_number'],
                'department' => $staffMember['department'],
                'area_of_work' => $staffMember['area_of_work'],
                'profile_image' => $staffMember['profile_image'],
                'user_type' => 'staff'
            ];
            $task['staff'][] = $staffInfo;
            $staffMap[$matchedName]['tasks'][] = $task;
        } else {
            // Include staff even if not found in staffMap (in case of name mismatch or non-staff user)
            // This handles cases where staff_assigned might contain names that don't match any staff user
            $task['staff'][] = [
                'name' => $staffName,
                'id' => '',
                'email' => '',
                'contact_number' => '',
                'department' => '',
                'area_of_work' => '',
                'profile_image' => '',
                'user_type' => 'unknown'
            ];
        }
    }
    
    $calendarData['tasks'][] = $task;
}

// Build staff list with tasks - only include verified staff users
foreach ($staffMap as $staffMember) {
    // Verify user_type is staff before including
    if (isset($staffMember['user_type']) && strtolower($staffMember['user_type']) === 'staff') {
        $calendarData['staff'][] = $staffMember;
    }
}

// Find available staff (no tasks or only completed/cancelled tasks)
foreach ($staffMap as $staffMember) {
    $hasActiveTasks = false;
    foreach ($staffMember['tasks'] as $task) {
        $taskStatus = strtolower($task['status']);
        if (!in_array($taskStatus, ['completed', 'done', 'cancelled', 'canceled'])) {
            // Check if task is in the future or ongoing
            try {
                $taskEnd = new DateTime($task['date_finish']);
                $taskEnd->setTime(23, 59, 59);
                if ($taskEnd >= $today) {
                    $hasActiveTasks = true;
                    break;
                }
            } catch (Throwable $e) {
                $hasActiveTasks = true;
                break;
            }
        }
    }
    
    if (!$hasActiveTasks) {
        // Only include staff users (double-check user_type)
        if (isset($staffMember['user_type']) && strtolower($staffMember['user_type']) === 'staff') {
            $calendarData['availableStaff'][] = [
                'id' => $staffMember['id'],
                'name' => $staffMember['name'],
                'email' => $staffMember['email'] ?? '',
                'contact_number' => $staffMember['contact_number'] ?? '',
                'department' => $staffMember['department'] ?? '',
                'area_of_work' => $staffMember['area_of_work'],
                'profile_image' => $staffMember['profile_image'] ?? '',
                'user_type' => 'staff',
                'status' => 'On Duty' // Default status since no leave tracking exists
            ];
        }
    }
}

echo json_encode($calendarData, JSON_PRETTY_PRINT);
?>

