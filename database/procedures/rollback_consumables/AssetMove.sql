CREATE PROCEDURE AssetMove(IN p_actor BIGINT, IN p_id BIGINT, IN p_version INT, IN p_action VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_data JSON)
BEGIN
    DECLARE v_status VARCHAR(20);
    DECLARE v_version INT;
    DECLARE v_holder VARCHAR(160);
    DECLARE v_department VARCHAR(120);
    DECLARE v_location VARCHAR(160);
    DECLARE v_note TEXT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    CALL AssetRequire(p_actor, 'inventory.manage');
    SELECT status, version INTO v_status, v_version FROM inventory_assets WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Asset not found.'; END IF;
    IF p_version IS NULL OR v_version <> p_version THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50008, MESSAGE_TEXT = 'Asset changed.'; END IF;
    IF p_action IS NULL OR NOT ((p_action = 'deploy' AND v_status IN ('available','returned')) OR (p_action IN ('transfer','return') AND v_status = 'deployed')
        OR (p_action = 'repair' AND v_status IN ('available','returned','damaged')) OR (p_action = 'restore' AND v_status = 'repair')
        OR (p_action = 'damage' AND v_status IN ('available','returned','deployed'))
        OR (p_action = 'replace' AND v_status IN ('deployed','damaged','repair'))
        OR (p_action = 'retire' AND v_status IN ('available','returned','damaged','repair','replaced'))) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Invalid asset transition.';
    END IF;
    SET v_holder = NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.holder_name'))), 'null');
    SET v_department = NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.department'))), 'null');
    SET v_location = NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.location'))), 'null');
    SET v_note = NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.note'))), 'null');
    IF p_action IN ('deploy','transfer') AND (COALESCE(v_holder, '') = '' OR COALESCE(v_department, '') = '' OR COALESCE(v_location, '') = '') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Employee, department and location required.';
    END IF;
    IF COALESCE(v_note, '') = '' OR COALESCE(v_location, '') = '' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Location and movement note required.';
    END IF;
    UPDATE inventory_assets SET
        status = CASE p_action WHEN 'deploy' THEN 'deployed' WHEN 'transfer' THEN 'deployed' WHEN 'return' THEN 'returned' WHEN 'damage' THEN 'damaged' WHEN 'replace' THEN 'replaced' WHEN 'repair' THEN 'repair' WHEN 'retire' THEN 'retired' ELSE 'available' END,
        holder_name = IF(p_action IN ('deploy','transfer'), v_holder, NULL),
        employee_code = IF(p_action IN ('deploy','transfer'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.employee_code')), 'null'), NULL),
        division = IF(p_action IN ('deploy','transfer'), NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.division'))), ''), 'null'), NULL),
        department = IF(p_action IN ('deploy','transfer'), v_department, NULL), location = v_location,
        accessories = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.accessories')), 'null'),
        deployed_at = IF(p_action IN ('deploy','transfer'), UTC_TIMESTAMP(), NULL),
        version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = p_id;
    CALL AssetAudit(p_actor, p_id, p_action, v_note);
    COMMIT;
END
