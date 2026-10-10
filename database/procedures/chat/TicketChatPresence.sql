CREATE PROCEDURE TicketChatPresence(IN p_actor BIGINT, IN p_ticket BIGINT, IN p_typing BOOLEAN, IN p_internal BOOLEAN, IN p_seen BIGINT)
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
    IF p_typing THEN
        CALL PermissionRequire(p_actor, 'tickets.comment');
        IF p_internal THEN
            CALL PermissionRequire(p_actor, 'tickets.internal');
            IF NOT v_staff THEN
                SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50006, MESSAGE_TEXT = 'Staff required.';
            END IF;
        END IF;
    END IF;
    SELECT COALESCE(MAX(id), 0) INTO v_seen FROM ticket_comments
        WHERE ticket_id = p_ticket AND id <= p_seen AND (internal = 0 OR v_staff);
    INSERT INTO ticket_chat_state (ticket_id, user_id, last_read_id, internal, typing_until)
    VALUES (p_ticket, p_actor, v_seen, p_internal, IF(p_typing, UTC_TIMESTAMP() + INTERVAL 8 SECOND, NULL))
    ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, v_seen), internal = p_internal,
        typing_until = IF(p_typing, UTC_TIMESTAMP() + INTERVAL 8 SECOND, NULL);
END
