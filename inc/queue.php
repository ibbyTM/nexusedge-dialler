<?php
declare(strict_types=1);

/**
 * Dial queue selection.
 *
 * Order:
 *  1. only leads assigned to this user with do_not_dial = 0
 *  2. callbacks due (callback_at <= now), oldest first
 *  3. then New, oldest created first
 *  4. then No-Answer where last_dial_at is more than 24h ago, fewest attempts first
 *  5. never Demo Booked, Cancelled, DQ or Invalid
 *  6. skip anything dialled in the last 4 hours
 */
function queue_where_sql(array &$params, int $userId): string
{
    $now = now();
    $fourHoursAgo = date('Y-m-d H:i:s', time() - 4 * 3600);
    $dayAgo = date('Y-m-d H:i:s', time() - 24 * 3600);
    $terminal = implode(',', array_fill(0, count(TERMINAL_STATUSES), '?'));

    $sql = "l.assigned_to = ? AND l.do_not_dial = 0
        AND l.dial_status NOT IN ($terminal)
        AND (l.last_dial_at IS NULL OR l.last_dial_at < ?)
        AND (
            (l.callback_at IS NOT NULL AND l.callback_at <= ?)
            OR l.dial_status = 'New'
            OR (l.dial_status = 'No-Answer' AND (l.last_dial_at IS NULL OR l.last_dial_at < ?))
        )";
    $params[] = $userId;
    foreach (TERMINAL_STATUSES as $s) {
        $params[] = $s;
    }
    $params[] = $fourHoursAgo;
    $params[] = $now;
    $params[] = $dayAgo;
    return $sql;
}

function queue_order_sql(array &$params): string
{
    $now = now();
    $params[] = $now;
    $params[] = $now;
    // Group first, then each group's own key: due callbacks by callback time,
    // New by created time, No-Answer by fewest attempts then oldest dial.
    return "CASE
            WHEN l.callback_at IS NOT NULL AND l.callback_at <= ? THEN 0
            WHEN l.dial_status = 'New' THEN 1
            ELSE 2 END ASC,
        CASE WHEN l.callback_at IS NOT NULL AND l.callback_at <= ? THEN l.callback_at END ASC,
        CASE WHEN l.dial_status = 'New' THEN l.created_at END ASC,
        l.dial_attempts ASC,
        l.last_dial_at ASC,
        l.created_at ASC,
        l.id ASC";
}

/** Next lead in this user's queue, optionally skipping one id. */
function queue_next_lead(int $userId, int $skipId = 0): ?array
{
    $params = [];
    $where = queue_where_sql($params, $userId);
    if ($skipId > 0) {
        $where .= ' AND l.id <> ?';
        $params[] = $skipId;
    }
    $order = queue_order_sql($params);
    return q_one(LEAD_SELECT . " WHERE $where ORDER BY $order LIMIT 1", $params);
}

/** How many leads are waiting in this user's queue. */
function queue_count(int $userId): int
{
    $params = [];
    $where = queue_where_sql($params, $userId);
    return (int)q_val("SELECT COUNT(*) FROM leads l WHERE $where", $params);
}
