CREATE PROCEDURE AssetAttachmentGet(IN p_actor BIGINT, IN p_asset BIGINT, IN p_attachment BIGINT)
BEGIN
    CALL AssetRequire(p_actor, 'inventory.view');
    SELECT id, filename, path, mime_type, size
    FROM inventory_asset_attachments
    WHERE asset_id = p_asset AND id = p_attachment;
END
