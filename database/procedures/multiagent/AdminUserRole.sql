CREATE PROCEDURE AdminUserRole(IN p_actor BIGINT, IN p_user BIGINT, IN p_roles JSON)
BEGIN
    DECLARE v_lock INT;
    DECLARE v_previous VARCHAR(20);
    DECLARE v_primary VARCHAR(20);
    DECLARE v_role VARCHAR(20);
    DECLARE v_index INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT id INTO v_lock FROM ticket_admin_lock WHERE id = 1 FOR UPDATE;
    CALL PermissionRequire(p_actor, 'users.roles');
    IF NOT EXISTS (
        SELECT 1 FROM users u WHERE u.id = p_actor AND (
            u.role IN ('admin','superadmin')
            OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role IN ('admin','superadmin'))
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    IF p_roles IS NULL OR JSON_TYPE(p_roles) <> 'ARRAY' OR JSON_LENGTH(p_roles) < 1 OR JSON_LENGTH(p_roles) > 6 OR p_user = p_actor THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Select one or more valid roles.';
    END IF;
    WHILE v_index < JSON_LENGTH(p_roles) DO
        IF JSON_TYPE(JSON_EXTRACT(p_roles, CONCAT('$[', v_index, ']'))) <> 'STRING' THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid role.';
        END IF;
        SET v_role = JSON_UNQUOTE(JSON_EXTRACT(p_roles, CONCAT('$[', v_index, ']')));
        IF v_role NOT IN ('requester','agent','dispatcher','inventory_manager','admin','superadmin') THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid role.';
        END IF;
        SET v_index = v_index + 1;
    END WHILE;
    SELECT role INTO v_previous FROM users WHERE id = p_user FOR UPDATE;
    IF v_previous IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'User not found.';
    END IF;
    IF (JSON_CONTAINS(p_roles, JSON_QUOTE('superadmin')) OR v_previous = 'superadmin'
        OR EXISTS (SELECT 1 FROM user_roles WHERE user_id = p_user AND role = 'superadmin'))
        AND NOT EXISTS (
            SELECT 1 FROM users u WHERE u.id = p_actor AND (
                u.role = 'superadmin'
                OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = 'superadmin')
            )
        ) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Only a superadmin can manage superadmins.';
    END IF;
    IF NOT JSON_CONTAINS(p_roles, JSON_QUOTE('agent')) AND NOT JSON_CONTAINS(p_roles, JSON_QUOTE('dispatcher'))
        AND NOT JSON_CONTAINS(p_roles, JSON_QUOTE('admin')) AND NOT JSON_CONTAINS(p_roles, JSON_QUOTE('superadmin'))
        AND (EXISTS (SELECT 1 FROM tickets t JOIN ticket_assignees ta ON ta.ticket_id = t.id WHERE ta.user_id = p_user AND t.status NOT IN ('resolved','closed'))
            OR EXISTS (SELECT 1 FROM ticket_categories WHERE default_assignee_id = p_user)) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50005, MESSAGE_TEXT = 'Reassign active tickets and category rules before removing this user from ticket handling roles.';
    END IF;

    SET v_primary = CASE
        WHEN JSON_CONTAINS(p_roles, JSON_QUOTE('superadmin')) THEN 'superadmin'
        WHEN JSON_CONTAINS(p_roles, JSON_QUOTE('admin')) THEN 'admin'
        WHEN JSON_CONTAINS(p_roles, JSON_QUOTE('dispatcher')) THEN 'dispatcher'
        WHEN JSON_CONTAINS(p_roles, JSON_QUOTE('agent')) THEN 'agent'
        WHEN JSON_CONTAINS(p_roles, JSON_QUOTE('inventory_manager')) THEN 'inventory_manager'
        ELSE 'requester'
    END;
    UPDATE users SET role = v_primary, updated_at = UTC_TIMESTAMP() WHERE id = p_user;
    DELETE FROM user_roles WHERE user_id = p_user;
    SET v_index = 0;
    WHILE v_index < JSON_LENGTH(p_roles) DO
        SET v_role = JSON_UNQUOTE(JSON_EXTRACT(p_roles, CONCAT('$[', v_index, ']')));
        IF v_role <> v_primary THEN
            INSERT IGNORE INTO user_roles (user_id, role) VALUES (p_user, v_role);
        END IF;
        SET v_index = v_index + 1;
    END WHILE;
    INSERT INTO admin_audit (actor_id, action, detail, created_at)
    VALUES (p_actor, 'role_changed', CONCAT('User #', p_user, ': ', v_previous, ' -> ', CAST(p_roles AS CHAR)), UTC_TIMESTAMP());
    COMMIT;
END
