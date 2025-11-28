<?php

if (!function_exists('staff_status_storage_path')) {
    function staff_status_storage_path(): string {
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/staff_status.json';
    }

    function load_staff_manual_statuses(): array {
        $path = staff_status_storage_path();
        if (!is_file($path)) {
            return [];
        }
        $json = @file_get_contents($path);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    function save_staff_manual_statuses(array $statuses): void {
        $path = staff_status_storage_path();
        @file_put_contents($path, json_encode($statuses, JSON_PRETTY_PRINT));
    }

    function staff_status_key(?string $name): string {
        return strtolower(trim((string)($name ?? '')));
    }

    function update_staff_manual_status(?string $name, string $status): void {
        $key = staff_status_key($name);
        if ($key === '') {
            return;
        }
        $statuses = load_staff_manual_statuses();
        if ($status === 'available') {
            unset($statuses[$key]);
        } else {
            $statuses[$key] = $status;
        }
        save_staff_manual_statuses($statuses);
    }

    function parse_staff_names($value): array {
        if ($value === null || $value === '') {
            return [];
        }
        $parts = preg_split('/,/', (string)$value);
        $names = [];
        foreach ($parts as $part) {
            $clean = trim((string)$part);
            if ($clean !== '') {
                $names[] = $clean;
            }
        }
        return $names;
    }

    function build_busy_staff_map(array $requests): array {
        $busy = [];
        $activeStatuses = [
            'pending',
            'waiting for staff',
            'in progress',
            'in-progress',
            'for pickup/confirmation',
            'waiting for pickup/confirmation',
            'waiting for pick up/confirmation',
        ];
        foreach ($requests as $req) {
            $status = strtolower((string)($req['status'] ?? ''));
            if (!in_array($status, $activeStatuses, true)) {
                continue;
            }
            foreach (parse_staff_names($req['staff_assigned'] ?? '') as $name) {
                $key = staff_status_key($name);
                if ($key !== '') {
                    $busy[$key] = true;
                }
            }
        }
        return $busy;
    }

    function staff_manual_status(?string $name, array $manualStatuses): string {
        $key = staff_status_key($name);
        if ($key === '') {
            return 'available';
        }
        $value = strtolower((string)($manualStatuses[$key] ?? 'available'));
        if (in_array($value, ['on_leave', 'leave'], true)) {
            return 'on_leave';
        }
        if (in_array($value, ['absence', 'absent'], true)) {
            return 'absence';
        }
        return 'available';
    }

    function staff_is_busy(?string $name, array $busyMap): bool {
        $key = staff_status_key($name);
        if ($key === '') {
            return false;
        }
        return isset($busyMap[$key]);
    }

    function derive_staff_display_status(?string $name, array $manualStatuses, array $busyMap): string {
        $manual = staff_manual_status($name, $manualStatuses);
        if ($manual === 'on_leave') {
            return 'On Leave';
        }
        if ($manual === 'absence') {
            return 'Absence';
        }
        if (staff_is_busy($name, $busyMap)) {
            return 'Assigned Work';
        }
        return 'Available';
    }

    function staff_is_available_for_assignment(?string $name, array $manualStatuses, array $busyMap, ?string $currentSelection = null): bool {
        if ($name === null || trim($name) === '') {
            return false;
        }
        if ($currentSelection !== null && strcasecmp($name, $currentSelection) === 0) {
            return true;
        }
        $manual = staff_manual_status($name, $manualStatuses);
        if ($manual === 'on_leave' || $manual === 'absence') {
            return false;
        }
        if (staff_is_busy($name, $busyMap)) {
            return false;
        }
        return true;
    }
}

