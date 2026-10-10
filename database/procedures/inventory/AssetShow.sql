CREATE PROCEDURE AssetShow(IN p_actor BIGINT, IN p_id BIGINT)
BEGIN
    CALL AssetRequire(p_actor, 'inventory.view');
    IF NOT EXISTS (SELECT 1 FROM inventory_assets WHERE id = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Asset not found.';
    END IF;
    SELECT a.*,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', e.id, 'action', e.action, 'note', e.note, 'snapshot', e.snapshot, 'created_at', e.created_at, 'actor', COALESCE(u.name, 'Former staff')) SEPARATOR ','), ''), ']')
            FROM inventory_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.asset_id = a.id), JSON_ARRAY()) AS history_json,
        COALESCE((SELECT CONCAT('[', COALESCE(GROUP_CONCAT(JSON_OBJECT('id', t.id, 'subject', t.subject, 'status', t.status) SEPARATOR ','), ''), ']')
            FROM inventory_ticket it JOIN tickets t ON t.id = it.ticket_id
            WHERE it.asset_id = a.id AND EXISTS (SELECT 1 FROM users u WHERE u.id = p_actor
                AND EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role = u.role AND rp.permission = 'tickets.view' AND rp.allowed = 1)
                AND (u.role IN ('admin','superadmin','dispatcher') OR t.requester_id = p_actor OR t.assignee_id = p_actor
                    OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor)))), JSON_ARRAY()) AS tickets_json
    FROM inventory_assets a WHERE a.id = p_id;
END
