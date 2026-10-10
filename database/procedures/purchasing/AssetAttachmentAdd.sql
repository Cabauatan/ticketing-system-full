CREATE PROCEDURE AssetAttachmentAdd(IN p_actor BIGINT, IN p_asset BIGINT, IN p_filename VARCHAR(200), IN p_path VARCHAR(500), IN p_mime VARCHAR(120), IN p_size BIGINT)
BEGIN
    CALL AssetRequire(p_actor, 'inventory.manage');
    IF NOT EXISTS (SELECT 1 FROM inventory_assets WHERE id = p_asset) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Asset not found.';
    END IF;
    INSERT INTO inventory_asset_attachments (asset_id, uploader_id, filename, path, mime_type, size, created_at)
    VALUES (p_asset, p_actor, p_filename, p_path, p_mime, p_size, UTC_TIMESTAMP());
    SELECT LAST_INSERT_ID() AS id;
END
