CREATE PROCEDURE AdminUsers(IN p_actor BIGINT, IN p_search VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_page INT)
BEGIN
    DECLARE v_offset INT DEFAULT 0;
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role = 'admin') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    SET v_offset = (GREATEST(p_page, 1) - 1) * 20;
    SELECT id, name, email, role, email_verified_at, COUNT(*) OVER() AS total_count
    FROM users WHERE p_search = '' OR LOCATE(p_search, name) > 0 OR LOCATE(p_search, email) > 0
    ORDER BY id LIMIT 20 OFFSET v_offset;
END
