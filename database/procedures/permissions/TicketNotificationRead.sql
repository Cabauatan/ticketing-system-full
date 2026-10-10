CREATE PROCEDURE TicketNotificationRead(IN p_actor BIGINT, IN p_id BIGINT)
BEGIN
    CALL PermissionRequire(p_actor, 'notifications.read');
    UPDATE ticket_notifications n JOIN tickets t ON t.id = n.ticket_id JOIN users u ON u.id = p_actor
    SET n.read_at = COALESCE(n.read_at, UTC_TIMESTAMP())
    WHERE n.id = p_id AND n.user_id = p_actor AND (t.requester_id = p_actor OR u.role IN ('admin','superadmin','dispatcher') OR t.assignee_id = p_actor
        OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor))
      AND (n.staff_only = 0 OR u.role IN ('agent','dispatcher','admin','superadmin'));
END
