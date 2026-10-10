CREATE PROCEDURE AssetStockMove(
    IN p_actor BIGINT,
    IN p_id BIGINT,
    IN p_version INT,
    IN p_action VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_quantity INT UNSIGNED,
    IN p_data JSON
)
BEGIN
    DECLARE v_type VARCHAR(30);
    DECLARE v_quantity INT UNSIGNED;
    DECLARE v_version INT;
    DECLARE v_recipient VARCHAR(160);
    DECLARE v_note TEXT;
    DECLARE v_ticket VARCHAR(30);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;
    CALL AssetRequire(p_actor, 'inventory.manage');
    SELECT type, quantity, version INTO v_type, v_quantity, v_version
        FROM inventory_assets WHERE id = p_id FOR UPDATE;

    IF v_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Inventory item not found.';
    END IF;
    IF v_version <> p_version THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50008, MESSAGE_TEXT = 'Inventory item changed.';
    END IF;
    IF v_type <> 'consumable' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Stock actions are only available for consumables.';
    END IF;
    IF p_action NOT IN ('issue', 'restock') OR p_quantity IS NULL OR p_quantity < 1 OR p_data IS NULL OR JSON_TYPE(p_data) <> 'OBJECT' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Choose a valid stock action and positive quantity.';
    END IF;

    SET v_recipient = NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.recipient'))), 'null');
    SET v_note = NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.note'))), 'null');
    SET v_ticket = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.ticket_id')), 'null');
    IF p_action = 'issue' AND COALESCE(v_recipient, '') = '' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Recipient is required when issuing consumables.';
    END IF;
    IF COALESCE(v_note, '') = '' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'A stock movement note is required.';
    END IF;
    IF p_action = 'issue' AND v_quantity < p_quantity THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50010, MESSAGE_TEXT = 'Not enough stock for this issue.';
    END IF;
    IF p_action = 'restock' AND v_quantity > 999999 - p_quantity THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50009, MESSAGE_TEXT = 'Stock quantity exceeds the supported limit.';
    END IF;

    UPDATE inventory_assets
    SET quantity = IF(p_action = 'issue', v_quantity - p_quantity, v_quantity + p_quantity),
        status = IF(IF(p_action = 'issue', v_quantity - p_quantity, v_quantity + p_quantity) = 0, 'out_of_stock', 'available'),
        version = version + 1,
        updated_at = UTC_TIMESTAMP()
    WHERE id = p_id;

    SET v_note = CONCAT(
        'Qty ', p_quantity, ' ', IF(p_action = 'issue', 'issued to ', 'restocked'),
        IF(p_action = 'issue', CONCAT(
            v_recipient,
            ' (', COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.department')), 'null'), 'no department'),
            IF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.employee_code')), 'null') IS NULL, '', CONCAT(', ID ', JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.employee_code')))),
            ')'
        ), ''),
        IF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.division')), 'null') IS NULL, '', CONCAT(' · Division ', JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.division')))),
        IF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.location')), 'null') IS NULL, '', CONCAT(' · ', JSON_UNQUOTE(JSON_EXTRACT(p_data, '$.location')))),
        IF(v_ticket IS NOT NULL, CONCAT(' · Ticket #', v_ticket), ''),
        ' · ', v_note
    );
    CALL AssetAudit(p_actor, p_id, IF(p_action = 'issue', 'stock_issue', 'stock_restock'), v_note);
    COMMIT;
END
