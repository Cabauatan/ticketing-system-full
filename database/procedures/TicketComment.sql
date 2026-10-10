CREATE PROCEDURE TicketComment(IN p_actor BIGINT, IN p_id BIGINT, IN p_body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_internal BOOLEAN)
BEGIN
    DECLARE v_staff BOOLEAN DEFAULT FALSE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    SET v_staff = EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('admin','agent'));
    IF NOT EXISTS (SELECT 1 FROM tickets WHERE id = p_id AND (v_staff OR requester_id = p_actor)) OR (p_internal AND NOT v_staff) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    START TRANSACTION;
    INSERT INTO ticket_comments (ticket_id, user_id, body, internal, created_at, updated_at)
    VALUES (p_id, p_actor, p_body, p_internal, UTC_TIMESTAMP(), UTC_TIMESTAMP());
    UPDATE tickets SET updated_at = UTC_TIMESTAMP() WHERE id = p_id;
    INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
    VALUES (p_id, p_actor, IF(p_internal, 'internal_note', 'commented'), 'Comment added', UTC_TIMESTAMP());
    COMMIT;
END
