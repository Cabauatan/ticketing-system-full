CREATE PROCEDURE AdminCategorySave(IN p_actor BIGINT, IN p_slug VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_name VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_active BOOLEAN, IN p_assignee BIGINT)
BEGIN
    DECLARE v_lock INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT id INTO v_lock FROM ticket_admin_lock WHERE id = 1 FOR UPDATE;
    CALL PermissionRequire(p_actor, 'categories.manage');
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('admin','superadmin')) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    IF p_assignee IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users WHERE id = p_assignee AND role IN ('agent','dispatcher','admin','superadmin')) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid assignee.';
    END IF;
    IF p_slug IS NULL OR p_slug NOT REGEXP '^[a-z][a-z0-9_-]{0,39}$' OR p_name IS NULL OR CHAR_LENGTH(TRIM(p_name)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Invalid category.';
    END IF;
    INSERT INTO ticket_categories (slug, name, active, default_assignee_id) VALUES (p_slug, p_name, p_active, p_assignee)
    ON DUPLICATE KEY UPDATE name = p_name, active = p_active, default_assignee_id = p_assignee;
    INSERT INTO admin_audit (actor_id, action, detail, created_at)
    VALUES (p_actor, 'category_saved', CONCAT(p_slug, ': ', p_name, '; active=', p_active, '; default assignee=', COALESCE(CAST(p_assignee AS CHAR), 'none')), UTC_TIMESTAMP());
    COMMIT;
END
