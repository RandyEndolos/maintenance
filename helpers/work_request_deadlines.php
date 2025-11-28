<?php

if (!function_exists('wr_deadline_timezone')) {
    function wr_deadline_timezone(): DateTimeZone {
        static $tz = null;
        if ($tz === null) {
            try {
                $tz = new DateTimeZone('Asia/Manila');
            } catch (Throwable $e) {
                $tz = new DateTimeZone('UTC');
            }
        }
        return $tz;
    }

    function wr_resolve_start_datetime(array $request): ?DateTimeImmutable {
        $tz = wr_deadline_timezone();
        $dateStart = trim((string)($request['date_start'] ?? ''));
        $timeStart = trim((string)($request['time_start'] ?? ''));
        if ($dateStart !== '') {
            $dateTime = $dateStart;
            if ($timeStart !== '') {
                $dateTime .= ' ' . $timeStart;
            }
            try {
                return new DateTimeImmutable($dateTime, $tz);
            } catch (Throwable $e) {
            }
        }
        $dateRequested = trim((string)($request['date_requested'] ?? ''));
        if ($dateRequested !== '') {
            try {
                return new DateTimeImmutable($dateRequested, $tz);
            } catch (Throwable $e) {
                try {
                    return new DateTimeImmutable($dateRequested);
                } catch (Throwable $e2) {
                }
            }
        }
        return null;
    }

    function wr_parse_duration_interval(?string $duration): ?DateInterval {
        if ($duration === null) {
            return null;
        }
        $duration = trim((string)$duration);
        if ($duration === '') {
            return null;
        }
        try {
            if (preg_match('/^P/i', $duration)) {
                return new DateInterval($duration);
            }
        } catch (Throwable $e) {
        }
        try {
            $interval = DateInterval::createFromDateString($duration);
            if ($interval instanceof DateInterval) {
                return $interval;
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    function wr_compute_deadline(array $request): ?DateTimeImmutable {
        $start = wr_resolve_start_datetime($request);
        $duration = wr_parse_duration_interval($request['time_duration'] ?? null);
        if (!$start || !$duration) {
            return null;
        }
        try {
            return $start->add($duration);
        } catch (Throwable $e) {
            return null;
        }
    }

    function wr_format_deadline_delta(DateTimeImmutable $deadline, DateTimeImmutable $now): string {
        $diff = $now->diff($deadline);
        $parts = [];
        if ($diff->d > 0) {
            $parts[] = $diff->d . 'd';
        }
        if ($diff->h > 0 && count($parts) < 2) {
            $parts[] = $diff->h . 'h';
        }
        if ($diff->i > 0 && count($parts) < 2) {
            $parts[] = $diff->i . 'm';
        }
        if (!$parts) {
            $parts[] = 'under 1h';
        }
        $label = implode(' ', $parts);
        return $deadline < $now ? $label . ' late' : 'in ' . $label;
    }

    function wr_enrich_deadline(array $request, ?DateTimeImmutable $now = null, int $warningHours = 10): array {
        $deadline = wr_compute_deadline($request);
        $now = $now ?? new DateTimeImmutable('now', wr_deadline_timezone());
        $state = 'unknown';
        $secondsRemaining = null;
        $humanDelta = 'N/A';
        $display = null;
        if ($deadline !== null) {
            $display = $deadline->format('M d, Y h:i A');
            $secondsRemaining = $deadline->getTimestamp() - $now->getTimestamp();
            if ($secondsRemaining < 0) {
                $state = 'overdue';
            } elseif ($secondsRemaining <= $warningHours * 3600) {
                $state = 'due_soon';
            } else {
                $state = 'on_track';
            }
            $humanDelta = wr_format_deadline_delta($deadline, $now);
        }
        return [
            'deadline_at' => $deadline ? $deadline->format(DateTimeInterface::ATOM) : null,
            'deadline_display' => $display,
            'deadline_state' => $state,
            'seconds_remaining' => $secondsRemaining,
            'human_delta' => $humanDelta,
        ];
    }

    function wr_is_active_status(?string $status): bool {
        $status = strtolower(trim((string)$status));
        if ($status === '') {
            return false;
        }
        $inactive = ['completed', 'done', 'task completed', 'cancelled', 'canceled'];
        return !in_array($status, $inactive, true);
    }
}


