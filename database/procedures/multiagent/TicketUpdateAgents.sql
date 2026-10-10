CREATE PROCEDURE TicketUpdateAgents(IN p_actor BIGINT, IN p_id BIGINT, IN p_version INT, IN p_status VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_priority VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_assignees JSON)
BEGIN
    DECLARE v_previous VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
    DECLARE v_lock INT;
    DECLARE v_index INT DEFAULT 0;
    DECLARE v_agent BIGINT;
    DECLARE v_primary BIGINT DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DROP TEMPORARY TABLE IF EXISTS tmp_ticket_agents;
        ROLLBACK;
        RESIGNAL;
    END;

    DROP TEMPORARY TABLE IF EXISTS tmp_ticket_agents;
    CREATE TEMPORARY TABLE tmp_ticket_agents (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY;
    START TRANSACTION;
    SELECT id INTO v_lock FROM ticket_admin_lock WHERE id = 1 FOR UPDATE;
    CALL PermissionRequire(p_actor, 'tickets.update');
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('superadmin','admin','agent','dispatcher')) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    IF p_status NOT IN ('new','open','in_progress','pending','resolved','closed') OR p_priority NOT IN ('low','medium','high','urgent') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid ticket values.';
    END IF;
    IF p_assignees IS NULL OR JSON_TYPE(p_assignees) <> 'ARRAY' OR JSON_LENGTH(p_assignees) > 50 THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Choose up to 50 agents.';
    END IF;
    WHILE v_index < JSON_LENGTH(p_assignees) DO
        IF JSON_TYPE(JSON_EXTRACT(p_assignees, CONCAT('$[', v_index, ']'))) <> 'INTEGER' THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid agent ID.';
        END IF;
        SET v_agent = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_assignees, CONCAT('$[', v_index, ']'))) AS UNSIGNED);
        IF v_agent < 1 OR NOT EXISTS (SELECT 1 FROM users WHERE id = v_agent AND role IN ('superadmin','admin','agent','dispatcher')) THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid agent ID.';
        END IF;
        IF EXISTS (SELECT 1 FROM tmp_ticket_agents WHERE id = v_agent) THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Duplicate agent ID.';
        END IF;
        INSERT INTO tmp_ticket_agents (id) VALUES (v_agent);
        SET v_index = v_index + 1;
    END WHILE;
    SELECT MIN(id) INTO v_primary FROM tmp_ticket_agents;
    SELECT status INTO v_previous FROM tickets WHERE id = p_id AND version = p_version FOR UPDATE;
    IF v_previous IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50001, MESSAGE_TEXT = 'Ticket changed. Refresh before saving.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('superadmin','admin','dispatcher'))
        AND NOT EXISTS (SELECT 1 FROM ticket_assignees WHERE ticket_id = p_id AND user_id = p_actor)
        AND NOT EXISTS (SELECT 1 FROM tickets WHERE id = p_id AND assignee_id = p_actor) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'This ticket is not assigned to you.';
    END IF;
    IF EXISTS (SELECT 1 FROM tmp_ticket_agents incoming
        WHERE NOT EXISTS (SELECT 1 FROM ticket_assignees current_agent WHERE current_agent.ticket_id = p_id AND current_agent.user_id = incoming.id))
        OR EXISTS (SELECT 1 FROM ticket_assignees current_agent
            WHERE current_agent.ticket_id = p_id
              AND NOT EXISTS (SELECT 1 FROM tmp_ticket_agents incoming WHERE incoming.id = current_agent.user_id)) THEN
        CALL PermissionRequire(p_actor, 'tickets.assign');
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
    UPDATE tickets SET status = p_status, priority = p_priority, assignee_id = v_primary,
        due_at = DATE_ADD(created_at, INTERVAL (CASE p_priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 8 WHEN 'medium' THEN 24 ELSE 72 END) HOUR),
        version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = p_id;
    INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
    SELECT ids.id, p_id, 'A ticket has been assigned to you.', 1, UTC_TIMESTAMP()
    FROM tmp_ticket_agents ids
    WHERE ids.id <> p_actor AND NOT EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = p_id AND ta.user_id = ids.id);
    DELETE FROM ticket_assignees WHERE ticket_id = p_id;
    INSERT INTO ticket_assignees (ticket_id, user_id)
    SELECT p_id, id FROM tmp_ticket_agents;
    IF v_previous <> p_status THEN
        INSERT INTO ticket_notifications (user_id, ticket_id, message, created_at)
        SELECT requester_id, id, CONCAT('Ticket status changed to ', p_status, '.'), UTC_TIMESTAMP()
        FROM tickets WHERE id = p_id AND requester_id <> p_actor;
    END IF;
    INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
    VALUES (p_id, p_actor, 'updated', CONCAT(v_previous, ' -> ', p_status, '; priority: ', p_priority, '; assigned user IDs: ', CAST(p_assignees AS CHAR)), UTC_TIMESTAMP());
    DROP TEMPORARY TABLE IF EXISTS tmp_ticket_agents;
    COMMIT;
END
