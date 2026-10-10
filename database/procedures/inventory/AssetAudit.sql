CREATE PROCEDURE AssetAudit(IN p_actor BIGINT, IN p_id BIGINT, IN p_action VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_note TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    INSERT INTO inventory_events (asset_id, actor_id, action, note, snapshot, created_at)
    SELECT id, p_actor, p_action, p_note, JSON_OBJECT(
        'asset_tag', asset_tag, 'serial_number', serial_number, 'name', name, 'type', type,
        'brand', brand, 'model', model, 'specifications', specifications,
        'purchase_date', purchase_date, 'warranty_until', warranty_until,
        'status', status, 'holder_name', holder_name, 'employee_code', employee_code,
        'department', department, 'location', location, 'accessories', accessories,
        'deployed_at', deployed_at, 'version', version
    ), UTC_TIMESTAMP() FROM inventory_assets WHERE id = p_id;
END
