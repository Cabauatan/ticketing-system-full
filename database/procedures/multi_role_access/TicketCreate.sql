CREATE PROCEDURE TicketCreate(IN p_actor BIGINT, IN p_subject VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_description MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_category VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_priority VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_id BIGINT;
    DECLARE v_lock INT;
    DECLARE v_assignee BIGINT DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT id INTO v_lock FROM ticket_admin_lock WHERE id = 1 FOR UPDATE;
    CALL PermissionRequire(p_actor, 'tickets.create');
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    IF p_priority NOT IN ('low','medium','high','urgent') OR NOT EXISTS (SELECT 1 FROM ticket_categories WHERE slug = p_category AND active = 1) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid ticket values.';
    END IF;
    SELECT c.default_assignee_id INTO v_assignee FROM ticket_categories c
    JOIN users u ON u.id = c.default_assignee_id AND (u.role IN ('agent','dispatcher','admin','superadmin') OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = u.id AND membership.role IN ('agent','dispatcher','admin','superadmin'))) WHERE c.slug = p_category;
    INSERT INTO tickets (requester_id, subject, description, category, priority, status, version, created_at, updated_at)
    VALUES (p_actor, p_subject, p_description, p_category, 'low', 'new', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP());
    SET v_id = LAST_INSERT_ID();
    UPDATE tickets SET assignee_id = v_assignee WHERE id = v_id;
    IF v_assignee IS NOT NULL THEN
        INSERT INTO ticket_assignees (ticket_id, user_id) VALUES (v_id, v_assignee);
    END IF;
    IF v_assignee IS NOT NULL AND v_assignee <> p_actor THEN
        INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
        VALUES (v_assignee, v_id, 'A new ticket was automatically assigned to you.', 1, UTC_TIMESTAMP());
    END IF;
    UPDATE tickets SET due_at = DATE_ADD(created_at, INTERVAL 72 HOUR) WHERE id = v_id;
    INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
    VALUES (v_id, p_actor, 'created', IF(v_assignee IS NULL, 'Ticket created', CONCAT('Ticket created; automatically assigned to user #', v_assignee)), UTC_TIMESTAMP());
    COMMIT;
    SELECT v_id AS id;
END
