CREATE PROCEDURE AssetTicketLink(IN p_actor BIGINT, IN p_id BIGINT, IN p_version INT, IN p_ticket BIGINT, IN p_link BOOLEAN)
BEGIN
    DECLARE v_version INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    CALL AssetRequire(p_actor, 'inventory.manage');
    CALL PermissionRequire(p_actor, 'tickets.view');
    CALL PermissionRequire(p_actor, 'tickets.update');
    IF NOT EXISTS (SELECT 1 FROM tickets t JOIN users u ON u.id = p_actor WHERE t.id = p_ticket AND (
        (u.role IN ('admin','superadmin','dispatcher') OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = u.id AND membership.role IN ('admin','superadmin','dispatcher'))) OR t.requester_id = p_actor OR t.assignee_id = p_actor
        OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor)
    )) THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Ticket not found.'; END IF;
    SELECT version INTO v_version FROM inventory_assets WHERE id = p_id FOR UPDATE;
    IF v_version IS NULL OR NOT EXISTS (SELECT 1 FROM tickets WHERE id = p_ticket) THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Asset or ticket not found.'; END IF;
    IF p_version IS NULL OR v_version <> p_version THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50008, MESSAGE_TEXT = 'Asset changed.'; END IF;
    IF p_link THEN INSERT IGNORE INTO inventory_ticket (asset_id, ticket_id) VALUES (p_id, p_ticket);
    ELSE DELETE FROM inventory_ticket WHERE asset_id = p_id AND ticket_id = p_ticket; END IF;
    IF ROW_COUNT() > 0 THEN
        UPDATE inventory_assets SET version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = p_id;
        CALL AssetAudit(p_actor, p_id, IF(p_link, 'ticket_linked', 'ticket_unlinked'), CONCAT('Ticket #', p_ticket));
    END IF;
    COMMIT;
END
