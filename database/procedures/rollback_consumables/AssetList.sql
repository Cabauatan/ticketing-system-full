CREATE PROCEDURE AssetList(IN p_actor BIGINT, IN p_search VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_status VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_type VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_page INT)
BEGIN
    DECLARE v_offset INT DEFAULT 0;
    DECLARE v_limit INT DEFAULT 25;
    CALL AssetRequire(p_actor, 'inventory.view');
    IF p_page IS NULL OR p_page < 0 OR p_page > 100000
        OR p_status NOT IN ('','available','deployed','returned','damaged','replaced','repair','retired')
        OR p_type NOT IN ('','desktop','laptop','monitor','printer','network','peripheral','other') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Invalid inventory filters.';
    END IF;
    IF p_page = 0 THEN SET v_limit = 10001; ELSE SET v_offset = (p_page - 1) * 25; END IF;
    SELECT a.*, COUNT(*) OVER() AS total_count FROM inventory_assets a
    WHERE (p_status = '' OR status = p_status) AND (p_type = '' OR type = p_type)
      AND (p_search = '' OR CONCAT_WS(' ', asset_tag, serial_number, name, brand, model, holder_name, employee_code, department, division, location) LIKE CONCAT('%', p_search, '%'))
    ORDER BY a.id DESC LIMIT v_limit OFFSET v_offset;
END
