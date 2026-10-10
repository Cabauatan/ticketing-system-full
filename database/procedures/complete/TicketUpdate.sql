CREATE PROCEDURE TicketUpdate(IN p_actor BIGINT, IN p_id BIGINT, IN p_version INT, IN p_status VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_priority VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_assignee BIGINT)
BEGIN
    DECLARE v_previous VARCHAR(20) DEFAULT NULL;
    DECLARE v_assignee BIGINT DEFAULT NULL;
    DECLARE v_lock INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT id INTO v_lock FROM ticket_admin_lock WHERE id = 1 FOR UPDATE;
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('admin','agent')) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    IF p_status NOT IN ('new','open','in_progress','pending','resolved','closed') OR p_priority NOT IN ('low','medium','high','urgent') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid ticket values.';
    END IF;
    IF p_assignee IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users WHERE id = p_assignee AND role IN ('admin','agent')) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Assignee must be an agent.';
    END IF;
    SELECT status, assignee_id INTO v_previous, v_assignee FROM tickets WHERE id = p_id AND version = p_version FOR UPDATE;
    IF v_previous IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50001, MESSAGE_TEXT = 'Ticket changed. Refresh before saving.';
    END IF;
    IF v_previous <> p_status AND NOT (
        (v_previous = 'new' AND p_status = 'open') OR
        (v_previous = 'open' AND p_status IN ('in_progress','pending')) OR
        (v_previous = 'in_progress' AND p_status IN ('pending','resolved')) OR
        (v_previous = 'pending' AND p_status IN ('open','in_progress')) OR
        (v_previous = 'resolved' AND p_status IN ('open','closed')) OR
        (v_previous = 'closed' AND p_status = 'open')) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid status transition.';
    END IF;
    UPDATE tickets SET status = p_status, priority = p_priority, assignee_id = p_assignee,
        due_at = DATE_ADD(created_at, INTERVAL (CASE p_priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 8 WHEN 'medium' THEN 24 ELSE 72 END) HOUR),
        version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = p_id;
    IF p_assignee IS NOT NULL AND NOT (v_assignee <=> p_assignee) AND p_assignee <> p_actor THEN
        INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
        VALUES (p_assignee, p_id, 'A ticket has been assigned to you.', 1, UTC_TIMESTAMP());
    END IF;
    IF v_previous <> p_status THEN
        INSERT INTO ticket_notifications (user_id, ticket_id, message, created_at)
        SELECT requester_id, id, CONCAT('Ticket status changed to ', p_status, '.'), UTC_TIMESTAMP()
        FROM tickets WHERE id = p_id AND requester_id <> p_actor;
    END IF;
    INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
    VALUES (p_id, p_actor, 'updated', CONCAT(v_previous, ' -> ', p_status, '; priority: ', p_priority, '; assignee: ', COALESCE(CAST(p_assignee AS CHAR), 'unassigned')), UTC_TIMESTAMP());
    COMMIT;
END
