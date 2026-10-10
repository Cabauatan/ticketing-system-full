CREATE PROCEDURE PermissionList(IN p_actor BIGINT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role = 'superadmin') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50006, MESSAGE_TEXT = 'Superadmin required.';
    END IF;
    SELECT rp.role, rp.permission, rp.allowed, pr.version
    FROM role_permissions rp JOIN permission_roles pr ON pr.role = rp.role
    ORDER BY rp.role, rp.permission;
END
