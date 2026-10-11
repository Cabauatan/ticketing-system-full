CREATE PROCEDURE AdminUsers(IN p_actor BIGINT, IN p_search VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_page INT)
BEGIN
    DECLARE v_offset INT DEFAULT 0;
    CALL PermissionRequire(p_actor, 'users.view');
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND (role IN ('admin','superadmin') OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = users.id AND membership.role IN ('admin','superadmin')))) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    SET v_offset = (GREATEST(p_page, 1) - 1) * 20;
    SELECT u.id, u.name, u.email, u.role,
        CONCAT('[', JSON_QUOTE(u.role), COALESCE((
            SELECT CONCAT(',', GROUP_CONCAT(JSON_QUOTE(ur.role) ORDER BY ur.role SEPARATOR ','))
            FROM user_roles ur WHERE ur.user_id = u.id
        ), ''), ']') AS roles_json,
        u.email_verified_at, COUNT(*) OVER() AS total_count
    FROM users u WHERE p_search = '' OR LOCATE(p_search, u.name) > 0 OR LOCATE(p_search, u.email) > 0
    ORDER BY id LIMIT 20 OFFSET v_offset;
END
