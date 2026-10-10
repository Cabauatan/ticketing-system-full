CREATE PROCEDURE AssetExport(IN p_actor BIGINT, IN p_search VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_status VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_type VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    CALL AssetRequire(p_actor, 'inventory.export');
    CALL AssetList(p_actor, p_search, p_status, p_type, 0);
END
