CREATE PROCEDURE TicketShow(IN p_actor BIGINT, IN p_id BIGINT)
BEGIN
    DECLARE v_staff BOOLEAN DEFAULT FALSE;
    CALL PermissionRequire(p_actor, 'tickets.view');
    SET v_staff = EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('superadmin','admin','agent','dispatcher'));
    IF NOT EXISTS (SELECT 1 FROM tickets t WHERE t.id = p_id AND (
        t.requester_id = p_actor
        OR EXISTS (SELECT 1 FROM users u WHERE u.id = p_actor AND u.role IN ('superadmin','admin','dispatcher'))
        OR t.assignee_id = p_actor
        OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor)
    )) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Ticket not found.';
    END IF;
    SELECT COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', au.id, 'name', au.name) SEPARATOR ','), ''), ']')
        FROM ticket_assignees ta JOIN users au ON au.id = ta.user_id WHERE ta.ticket_id = t.id), JSON_ARRAY()) AS assignees_json,
        t.*, u.name AS requester_name, a.name AS assignee_name,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', c.id, 'body', c.body, 'internal', c.internal, 'created_at', c.created_at, 'author', cu.name) SEPARATOR ','), ''), ']')
          FROM ticket_comments c JOIN users cu ON cu.id = c.user_id
          WHERE c.ticket_id = t.id AND (v_staff OR c.internal = 0)), JSON_ARRAY()) AS comments_json,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', e.id, 'action', e.action, 'detail', e.detail, 'created_at', e.created_at, 'actor', COALESCE(eu.name, 'System')) SEPARATOR ','), ''), ']')
          FROM ticket_events e LEFT JOIN users eu ON eu.id = e.actor_id
          WHERE e.ticket_id = t.id AND (v_staff OR e.action <> 'internal_note')), JSON_ARRAY()) AS events_json
    FROM tickets t JOIN users u ON u.id = t.requester_id LEFT JOIN users a ON a.id = t.assignee_id WHERE t.id = p_id;
END
