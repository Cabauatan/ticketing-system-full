CREATE PROCEDURE TicketShow(IN p_actor BIGINT, IN p_id BIGINT)
BEGIN
    DECLARE v_staff BOOLEAN DEFAULT FALSE;
    SET v_staff = EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('admin','agent'));
    IF NOT EXISTS (SELECT 1 FROM tickets WHERE id = p_id AND (v_staff OR requester_id = p_actor)) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Ticket not found.';
    END IF;
    SELECT t.*, u.name AS requester_name, a.name AS assignee_name,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', c.id, 'body', c.body, 'internal', c.internal, 'created_at', c.created_at, 'author', cu.name) SEPARATOR ','), ''), ']')
          FROM ticket_comments c JOIN users cu ON cu.id = c.user_id
          WHERE c.ticket_id = t.id AND (v_staff OR c.internal = 0)), JSON_ARRAY()) AS comments_json,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', e.id, 'action', e.action, 'detail', e.detail, 'created_at', e.created_at, 'actor', eu.name) SEPARATOR ','), ''), ']')
          FROM ticket_events e JOIN users eu ON eu.id = e.actor_id
          WHERE e.ticket_id = t.id AND (v_staff OR e.action <> 'internal_note')), JSON_ARRAY()) AS events_json
    FROM tickets t JOIN users u ON u.id = t.requester_id LEFT JOIN users a ON a.id = t.assignee_id WHERE t.id = p_id;
END
