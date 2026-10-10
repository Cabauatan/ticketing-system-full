CREATE PROCEDURE TicketAssets(IN p_actor BIGINT, IN p_ticket BIGINT)
BEGIN
    CALL AssetRequire(p_actor, 'inventory.view');
    CALL PermissionRequire(p_actor, 'tickets.view');
    IF NOT EXISTS (SELECT 1 FROM tickets t JOIN users u ON u.id = p_actor WHERE t.id = p_ticket AND (
        u.role IN ('admin','superadmin','dispatcher') OR t.requester_id = p_actor OR t.assignee_id = p_actor
        OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor)
    )) THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Ticket not found.'; END IF;
    SELECT a.id, a.asset_tag, a.name, a.status FROM inventory_assets a JOIN inventory_ticket it ON it.asset_id = a.id WHERE it.ticket_id = p_ticket ORDER BY a.asset_tag;
END
