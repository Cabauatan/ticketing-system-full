CREATE PROCEDURE AdminAudit(IN p_actor BIGINT)
BEGIN
    CALL PermissionRequire(p_actor, 'audit.view');
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND (role IN ('admin','superadmin') OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = users.id AND membership.role IN ('admin','superadmin')))) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;

    SELECT audit_entries.source, audit_entries.id, audit_entries.action,
        audit_entries.detail, audit_entries.created_at, audit_entries.actor
    FROM (
        SELECT CONVERT('admin' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            a.id, CONVERT(a.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
            CONVERT(a.detail USING utf8mb4) COLLATE utf8mb4_unicode_ci AS detail,
            a.created_at, CONVERT(COALESCE(u.name, 'Former staff') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor
        FROM admin_audit a
        LEFT JOIN users u ON u.id = a.actor_id

        UNION ALL

        SELECT CONVERT('inventory' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            e.id, CONVERT(e.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
            CONVERT(CONCAT(
                COALESCE(NULLIF(a.asset_tag, ''), CONCAT('Asset #', a.id)),
                ' · ', a.name,
                IF(COALESCE(e.note, '') = '', '', CONCAT(' · ', e.note))
            ) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS detail,
            e.created_at, CONVERT(COALESCE(u.name, 'Former staff') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor
        FROM inventory_events e
        JOIN inventory_assets a ON a.id = e.asset_id
        LEFT JOIN users u ON u.id = e.actor_id

        UNION ALL

        SELECT CONVERT('ticket' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            e.id, CONVERT(e.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
            CONVERT(CONCAT(
                'Ticket #', t.id, ' · ', t.subject,
                IF(COALESCE(e.detail, '') = '', '', CONCAT(' · ', e.detail))
            ) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS detail,
            e.created_at, CONVERT(COALESCE(u.name, 'Former staff') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor
        FROM ticket_events e
        JOIN tickets t ON t.id = e.ticket_id
        LEFT JOIN users u ON u.id = e.actor_id
    ) AS audit_entries
    ORDER BY audit_entries.created_at DESC, audit_entries.id DESC, audit_entries.source
    LIMIT 100;
END
