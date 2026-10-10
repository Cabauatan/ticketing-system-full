CREATE PROCEDURE TicketChatRead(IN p_actor BIGINT, IN p_ticket BIGINT, IN p_after BIGINT)
BEGIN
    DECLARE v_staff BOOLEAN DEFAULT FALSE;
    DECLARE v_seen BIGINT DEFAULT 0;
    CALL PermissionRequire(p_actor, 'tickets.view');
    SET v_staff = EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('agent','dispatcher','admin','superadmin'));
    IF NOT EXISTS (SELECT 1 FROM tickets t WHERE t.id = p_ticket AND (
        t.requester_id = p_actor
        OR EXISTS (SELECT 1 FROM users u WHERE u.id = p_actor AND u.role IN ('superadmin','admin','dispatcher'))
        OR t.assignee_id = p_actor
        OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor)
    )) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Ticket not found.';
    END IF;
    SELECT COALESCE(MAX(last_read_id), 0) INTO v_seen FROM ticket_chat_state WHERE ticket_id = p_ticket AND user_id = p_actor;
    SELECT
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', c.id, 'user_id', c.user_id, 'author', c.author, 'body', c.body, 'internal', c.internal, 'created_at', c.created_at) SEPARATOR ','), ''), ']')
            FROM (SELECT tc.*, u.name AS author FROM ticket_comments tc JOIN users u ON u.id = tc.user_id
                WHERE tc.ticket_id = p_ticket AND tc.id > p_after AND (tc.internal = 0 OR v_staff)
                ORDER BY tc.id LIMIT 100) c), JSON_ARRAY()) AS messages_json,
        (SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = p_ticket AND id > v_seen AND user_id <> p_actor AND (internal = 0 OR v_staff)) AS unread,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', u.id, 'name', u.name) SEPARATOR ','), ''), ']')
            FROM ticket_chat_state s JOIN users u ON u.id = s.user_id JOIN tickets t ON t.id = s.ticket_id
            WHERE s.ticket_id = p_ticket AND s.user_id <> p_actor AND s.typing_until > UTC_TIMESTAMP()
                AND (s.internal = 0 OR v_staff)
                AND (u.id = t.requester_id OR u.role IN ('agent','dispatcher','admin','superadmin'))
                AND (s.internal = 0 OR u.role IN ('agent','dispatcher','admin','superadmin'))
                AND (u.role = 'superadmin' OR EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role = u.role AND rp.permission = 'tickets.comment' AND rp.allowed = 1))
        ), JSON_ARRAY()) AS typing_json;
END
