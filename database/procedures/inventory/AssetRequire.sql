CREATE PROCEDURE AssetRequire(IN p_actor BIGINT, IN p_permission VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    CALL PermissionRequire(p_actor, 'inventory.view');
    CALL PermissionRequire(p_actor, p_permission);
    IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = p_actor AND (
        u.role IN ('admin','superadmin','inventory_manager')
        OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role IN ('admin','superadmin','inventory_manager'))
    )) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50006, MESSAGE_TEXT = 'Staff access required.';
    END IF;
END
