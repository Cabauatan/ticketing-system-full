CREATE PROCEDURE AssetSave(IN p_actor BIGINT, IN p_id BIGINT, IN p_version INT, IN p_data JSON)
BEGIN
    DECLARE v_id BIGINT;
    DECLARE v_version INT;
    DECLARE v_tag VARCHAR(60);
    DECLARE v_name VARCHAR(160);
    DECLARE v_type VARCHAR(30);
    DECLARE v_serial VARCHAR(120);
    DECLARE v_quantity_text VARCHAR(30);
    DECLARE v_cost_text VARCHAR(40);
    DECLARE v_quantity INT UNSIGNED;
    DECLARE v_unit_cost DECIMAL(12,2);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    CALL AssetRequire(p_actor, 'inventory.manage');
    SET v_tag = TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.asset_tag')));
    SET v_name = TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.name')));
    SET v_type = JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.type'));
    SET v_serial = NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.serial_number'))), ''), 'null');
    SET v_quantity_text = NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.quantity')), ''), 'null');
    SET v_cost_text = NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.unit_cost')), ''), 'null');
    IF v_quantity_text IS NULL THEN SET v_quantity_text = '1'; END IF;
    IF v_quantity_text NOT REGEXP '^[1-9][0-9]{0,5}$' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Quantity must be a positive whole number.';
    END IF;
    SET v_quantity = CAST(v_quantity_text AS UNSIGNED);
    IF v_cost_text IS NOT NULL AND v_cost_text NOT REGEXP '^[0-9]{1,10}([.][0-9]{1,2})?$' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Unit cost is invalid.';
    END IF;
    SET v_unit_cost = CAST(v_cost_text AS DECIMAL(12,2));
    IF v_serial IS NOT NULL AND v_quantity <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Serialized assets must have quantity 1. Create a record for each serial number.';
    END IF;
    IF p_data IS NULL OR JSON_TYPE(p_data) <> 'OBJECT' OR JSON_TYPE(JSON_EXTRACT(p_data, '$.asset_tag')) <> 'STRING' OR JSON_TYPE(JSON_EXTRACT(p_data, '$.name')) <> 'STRING' OR v_tag IS NULL OR v_tag = '' OR v_name IS NULL OR v_name = ''
        OR v_type IS NULL OR v_type NOT IN ('desktop','laptop','monitor','printer','network','peripheral','other') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Asset tag, name and valid type required.';
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO inventory_assets (asset_tag, name, type, quantity, unit_cost, created_at, updated_at)
        VALUES (v_tag, v_name, v_type, v_quantity, v_unit_cost, UTC_TIMESTAMP(), UTC_TIMESTAMP());
        SET v_id = LAST_INSERT_ID();
    ELSE
        SELECT version INTO v_version FROM inventory_assets WHERE id = p_id FOR UPDATE;
        IF v_version IS NULL THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Asset not found.'; END IF;
        IF p_version IS NULL OR v_version <> p_version THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50008, MESSAGE_TEXT = 'Asset changed.'; END IF;
        SET v_id = p_id;
    END IF;
    UPDATE inventory_assets SET asset_tag = v_tag, name = v_name, type = v_type, quantity = v_quantity, unit_cost = v_unit_cost,
        serial_number = v_serial,
        brand = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.brand')), 'null'),
        model = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.model')), 'null'),
        division = IF(JSON_CONTAINS_PATH(p_data, 'one', '$.division'), NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.division'))), ''), 'null'), division),
        specifications = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.specifications')), 'null'),
        location = IF(status = 'deployed', location, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.location')), 'null')),
        accessories = IF(status = 'deployed', accessories, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.accessories')), 'null')),
        purchase_date = NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.purchase_date')), ''), 'null'),
        warranty_until = NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.warranty_until')), ''), 'null'),
        version = IF(p_id IS NULL, 1, version + 1), updated_at = UTC_TIMESTAMP()
    WHERE id = v_id;
    IF EXISTS (SELECT 1 FROM inventory_assets WHERE id = v_id AND warranty_until < purchase_date) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Warranty precedes purchase date.';
    END IF;
    CALL AssetAudit(p_actor, v_id, IF(p_id IS NULL, 'created', 'edited'), NULL);
    COMMIT;
    SELECT v_id AS id;
END
