CREATE PROCEDURE TicketReport(IN p_actor BIGINT, IN p_days INT)
BEGIN
    IF p_days NOT IN (0, 7, 30, 90) OR p_days IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid report period.';
    END IF;
    WITH scoped AS (
        SELECT t.* FROM tickets t JOIN users viewer ON viewer.id = p_actor
        WHERE (t.requester_id = p_actor OR viewer.role IN ('admin','agent'))
          AND (p_days = 0 OR t.created_at >= UTC_TIMESTAMP() - INTERVAL p_days DAY)
    )
    SELECT 'status' AS dimension, status AS label, COUNT(*) AS total FROM scoped GROUP BY status
    UNION ALL
    SELECT 'priority', priority, COUNT(*) FROM scoped GROUP BY priority
    UNION ALL
    SELECT 'category', category, COUNT(*) FROM scoped GROUP BY category
    UNION ALL
    SELECT 'sla', 'overdue', COUNT(*) FROM scoped WHERE status NOT IN ('resolved','closed') AND due_at < UTC_TIMESTAMP()
    UNION ALL
    SELECT 'workload', COALESCE(u.name, 'Unassigned'), COUNT(*)
    FROM scoped t LEFT JOIN users u ON u.id = t.assignee_id
    WHERE t.status NOT IN ('resolved', 'closed')
    GROUP BY t.assignee_id, u.name
    ORDER BY dimension, total DESC, label;
END
