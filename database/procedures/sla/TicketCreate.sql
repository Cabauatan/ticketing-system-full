CREATE PROCEDURE TicketCreate(IN p_actor BIGINT, IN p_subject VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_description MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_category VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_priority VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_id BIGINT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    IF p_priority NOT IN ('low','medium','high','urgent') OR p_category NOT IN ('hardware','software','network','access','other') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid ticket values.';
    END IF;
    START TRANSACTION;
    INSERT INTO tickets (requester_id, subject, description, category, priority, status, version, created_at, updated_at)
    VALUES (p_actor, p_subject, p_description, p_category, p_priority, 'new', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP());
    SET v_id = LAST_INSERT_ID();
    UPDATE tickets SET due_at = DATE_ADD(created_at, INTERVAL (CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 8 WHEN 'medium' THEN 24 ELSE 72 END) HOUR) WHERE id = v_id;
    INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
    VALUES (v_id, p_actor, 'created', 'Ticket created', UTC_TIMESTAMP());
    COMMIT;
    SELECT v_id AS id;
END
