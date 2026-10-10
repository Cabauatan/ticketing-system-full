CREATE PROCEDURE AdminUserRole(IN p_actor BIGINT, IN p_user BIGINT, IN p_role VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_lock INT;
    DECLARE v_previous VARCHAR(20);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT id INTO v_lock FROM ticket_admin_lock WHERE id = 1 FOR UPDATE;
    CALL PermissionRequire(p_actor, 'users.roles');
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('admin','superadmin')) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    IF p_role IS NULL OR p_role NOT IN ('requester','agent','admin','superadmin') OR p_user = p_actor THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Cannot change your own role.';
    END IF;
    SELECT role INTO v_previous FROM users WHERE id = p_user FOR UPDATE;
    IF v_previous IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'User not found.';
    END IF;
    IF (p_role = 'superadmin' OR v_previous = 'superadmin') AND NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role = 'superadmin') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Only a superadmin can manage superadmins.';
    END IF;
    IF p_role = 'requester' AND (EXISTS (SELECT 1 FROM tickets WHERE assignee_id = p_user AND status NOT IN ('resolved','closed'))
        OR EXISTS (SELECT 1 FROM ticket_categories WHERE default_assignee_id = p_user)) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50005, MESSAGE_TEXT = 'Reassign active tickets and category rules before demoting this user.';
    END IF;
    UPDATE users SET role = p_role, updated_at = UTC_TIMESTAMP() WHERE id = p_user;
    INSERT INTO admin_audit (actor_id, action, detail, created_at)
    VALUES (p_actor, 'role_changed', CONCAT('User #', p_user, ': ', v_previous, ' -> ', p_role), UTC_TIMESTAMP());
    COMMIT;
END
