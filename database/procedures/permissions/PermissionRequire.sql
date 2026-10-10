CREATE PROCEDURE PermissionRequire(IN p_actor BIGINT, IN p_permission VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM users u WHERE u.id = p_actor AND (
            u.role = 'superadmin'
            OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = 'superadmin')
            OR EXISTS (
                SELECT 1 FROM role_permissions rp WHERE rp.role = u.role
                AND rp.permission = p_permission AND rp.allowed = 1
            )
            OR EXISTS (
                SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role = ur.role
                WHERE ur.user_id = u.id AND rp.permission = p_permission AND rp.allowed = 1
            )
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50006, MESSAGE_TEXT = 'Permission denied.';
    END IF;
END
