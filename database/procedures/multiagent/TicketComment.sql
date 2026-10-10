CREATE PROCEDURE TicketComment(IN p_actor BIGINT, IN p_id BIGINT, IN p_body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_internal BOOLEAN)
BEGIN
    DECLARE v_staff BOOLEAN DEFAULT FALSE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    CALL PermissionRequire(p_actor, 'tickets.comment');
    IF p_internal THEN CALL PermissionRequire(p_actor, 'tickets.internal'); END IF;
    SET v_staff = EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('superadmin','admin','agent','dispatcher'));
    IF NOT EXISTS (SELECT 1 FROM tickets t WHERE t.id = p_id AND (
        t.requester_id = p_actor
        OR EXISTS (SELECT 1 FROM users u WHERE u.id = p_actor AND u.role IN ('superadmin','admin','dispatcher'))
        OR t.assignee_id = p_actor
        OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor)
    )) OR (p_internal AND NOT v_staff) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    START TRANSACTION;
    -- Serialize with assignment updates before choosing notification recipients.
    UPDATE tickets SET updated_at = UTC_TIMESTAMP() WHERE id = p_id;
    INSERT INTO ticket_comments (ticket_id, user_id, body, internal, created_at, updated_at)
    VALUES (p_id, p_actor, p_body, p_internal, UTC_TIMESTAMP(), UTC_TIMESTAMP());
    UPDATE tickets SET updated_at = UTC_TIMESTAMP() WHERE id = p_id;
    INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
    VALUES (p_id, p_actor, IF(p_internal, 'internal_note', 'commented'), 'Comment added', UTC_TIMESTAMP());
    INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
    SELECT u.id, t.id, IF(p_internal, 'A new internal note was added.', 'A new reply was added.'), p_internal, UTC_TIMESTAMP()
    FROM tickets t JOIN users u ON (u.id = t.requester_id OR (u.role IN ('agent','dispatcher','admin','superadmin') AND EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = u.id)))
    WHERE t.id = p_id AND u.id <> p_actor
      AND (p_internal = 0 OR u.role IN ('agent','dispatcher','admin','superadmin'));
    COMMIT;
END
