CREATE PROCEDURE PermissionSet(IN p_actor BIGINT, IN p_role VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_version INT, IN p_permissions JSON)
BEGIN
    DECLARE v_lock INT;
    DECLARE v_version INT;
    DECLARE v_index INT DEFAULT 0;
    DECLARE v_permission VARCHAR(255);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT id INTO v_lock FROM ticket_admin_lock WHERE id = 1 FOR UPDATE;
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role = 'superadmin') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50006, MESSAGE_TEXT = 'Superadmin required.';
    END IF;
    IF p_role IS NULL OR p_role NOT IN ('requester','agent','dispatcher','inventory_manager','admin') OR p_permissions IS NULL OR JSON_TYPE(p_permissions) <> 'ARRAY' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid permissions.';
    END IF;
    SELECT version INTO v_version FROM permission_roles WHERE role = p_role FOR UPDATE;
    IF p_version IS NULL OR p_version <> v_version THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50007, MESSAGE_TEXT = 'Permissions changed. Refresh before saving.';
    END IF;
    IF JSON_LENGTH(p_permissions) > (SELECT COUNT(*) FROM role_permissions WHERE role = p_role) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Permission exceeds role boundary.';
    END IF;
    WHILE v_index < JSON_LENGTH(p_permissions) DO
        IF JSON_TYPE(JSON_EXTRACT(p_permissions, CONCAT('$[', v_index, ']'))) <> 'STRING' THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid permission value.';
        END IF;
        SET v_permission = JSON_UNQUOTE(JSON_EXTRACT(p_permissions, CONCAT('$[', v_index, ']')));
        IF NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role = p_role AND BINARY rp.permission = BINARY v_permission) THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Permission exceeds role boundary.';
        END IF;
        SET v_index = v_index + 1;
    END WHILE;
    UPDATE role_permissions rp SET allowed = JSON_CONTAINS(p_permissions, JSON_QUOTE(rp.permission)) WHERE rp.role = p_role;
    UPDATE permission_roles SET version = version + 1 WHERE role = p_role;
    INSERT INTO admin_audit (actor_id, action, detail, created_at)
    VALUES (p_actor, 'permissions_changed', CONCAT(p_role, ' v', v_version + 1, ': ', CONCAT(JSON_LENGTH(p_permissions), ' permissions enabled')), UTC_TIMESTAMP());
    COMMIT;
    SELECT p_role AS role, v_version + 1 AS version;
END
