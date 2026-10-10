CREATE PROCEDURE AssetAttachmentList(IN p_actor BIGINT, IN p_asset BIGINT)
BEGIN
    CALL AssetRequire(p_actor, 'inventory.view');
    IF NOT EXISTS (SELECT 1 FROM inventory_assets WHERE id = p_asset) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Asset not found.';
    END IF;
    SELECT a.id, a.filename, a.mime_type, a.size, a.created_at
    FROM inventory_asset_attachments a
    WHERE a.asset_id = p_asset
    ORDER BY a.id DESC;
END
