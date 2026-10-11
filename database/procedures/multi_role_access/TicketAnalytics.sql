CREATE PROCEDURE TicketAnalytics(IN p_actor BIGINT, IN p_from DATE, IN p_to DATE)
BEGIN
    DECLARE v_staff BOOLEAN DEFAULT FALSE;
    CALL PermissionRequire(p_actor, 'reports.view');
    IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR DATEDIFF(p_to, p_from) > 365 THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Choose a date range of at most 366 days.';
    END IF;
    SET v_staff = EXISTS (SELECT 1 FROM users WHERE id = p_actor AND (role IN ('agent','dispatcher','admin','superadmin') OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = users.id AND membership.role IN ('agent','dispatcher','admin','superadmin'))));
    WITH scoped AS (
        SELECT t.* FROM tickets t
        WHERE (EXISTS (SELECT 1 FROM users u WHERE u.id = p_actor AND (u.role IN ('admin','superadmin','dispatcher') OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = u.id AND membership.role IN ('admin','superadmin','dispatcher'))))
            OR t.requester_id = p_actor
            OR t.assignee_id = p_actor
            OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor))
          AND t.created_at >= p_from AND t.created_at < DATE_ADD(p_to, INTERVAL 1 DAY)
    ), breakdown AS (
        SELECT 'status' AS dimension, status AS label, COUNT(*) AS total FROM scoped GROUP BY status
        UNION ALL SELECT 'priority', priority, COUNT(*) FROM scoped GROUP BY priority
        UNION ALL SELECT 'category', category, COUNT(*) FROM scoped GROUP BY category
    ), daily AS (
        SELECT DATE(created_at) AS day, COUNT(*) AS total FROM scoped GROUP BY DATE(created_at)
    ), assigned AS (
        SELECT ta.user_id, COUNT(*) AS assigned,
            SUM(t.status NOT IN ('resolved','closed')) AS active,
            SUM(t.status IN ('resolved','closed')) AS completed,
            SUM(t.status NOT IN ('resolved','closed') AND t.due_at < UTC_TIMESTAMP()) AS overdue
        FROM scoped t JOIN ticket_assignees ta ON ta.ticket_id = t.id GROUP BY ta.user_id
    ), public_replies AS (
        SELECT c.user_id, c.ticket_id, c.created_at, t.created_at AS ticket_created_at,
            ROW_NUMBER() OVER (PARTITION BY c.ticket_id ORDER BY c.created_at, c.id) AS reply_number
        FROM scoped t JOIN ticket_comments c ON c.ticket_id = t.id
        JOIN users u ON u.id = c.user_id AND ((u.role IN ('agent','admin','superadmin') OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = u.id AND membership.role IN ('agent','admin','superadmin')))
            OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = 'agent'))
        WHERE c.internal = 0 AND c.user_id <> t.requester_id
    ), replies AS (
        SELECT user_id, COUNT(*) AS public_replies, COUNT(DISTINCT ticket_id) AS tickets_replied,
            SUM(reply_number = 1) AS first_replies,
            ROUND(AVG(IF(reply_number = 1, GREATEST(0, TIMESTAMPDIFF(SECOND, ticket_created_at, created_at)) / 60.0, NULL)), 1) AS first_reply_minutes
        FROM public_replies GROUP BY user_id
    )
    SELECT
        (SELECT JSON_OBJECT(
            'total', COUNT(*),
            'active', COALESCE(SUM(status NOT IN ('resolved','closed')), 0),
            'completed', COALESCE(SUM(status IN ('resolved','closed')), 0),
            'overdue', COALESCE(SUM(status NOT IN ('resolved','closed') AND due_at < UTC_TIMESTAMP()), 0),
            'within_deadline', COALESCE(SUM(status NOT IN ('resolved','closed') AND due_at >= UTC_TIMESTAMP()), 0),
            'no_deadline', COALESCE(SUM(status NOT IN ('resolved','closed') AND due_at IS NULL), 0),
            'unassigned', COALESCE(SUM(status NOT IN ('resolved','closed') AND NOT EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = scoped.id)), 0)
        ) FROM scoped) AS summary_json,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('dimension', dimension, 'label', label, 'total', total) SEPARATOR ','), ''), ']') FROM breakdown), JSON_ARRAY()) AS breakdown_json,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('date', day, 'total', total) SEPARATOR ','), ''), ']') FROM daily), JSON_ARRAY()) AS trend_json,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT(
            'id', u.id, 'name', u.name,
            'assigned', COALESCE(a.assigned, 0), 'active', COALESCE(a.active, 0),
            'completed', COALESCE(a.completed, 0), 'overdue', COALESCE(a.overdue, 0),
            'public_replies', COALESCE(r.public_replies, 0), 'tickets_replied', COALESCE(r.tickets_replied, 0),
            'first_replies', COALESCE(r.first_replies, 0), 'first_reply_minutes', r.first_reply_minutes
        ) SEPARATOR ','), ''), ']') FROM users u LEFT JOIN assigned a ON a.user_id = u.id LEFT JOIN replies r ON r.user_id = u.id
        WHERE v_staff AND ((u.role IN ('agent','admin','superadmin') OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = u.id AND membership.role IN ('agent','admin','superadmin')))
            OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = 'agent'))
          AND (a.user_id IS NOT NULL OR r.user_id IS NOT NULL)), JSON_ARRAY()) AS agents_json;
END
