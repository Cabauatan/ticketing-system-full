CREATE PROCEDURE TicketNotifications(IN p_actor BIGINT, IN p_page INT)
BEGIN
    DECLARE v_offset INT DEFAULT 0;
    SET v_offset = (GREATEST(COALESCE(p_page, 1), 1) - 1) * 25;
    SELECT n.id, n.ticket_id, n.message, n.created_at, n.read_at, t.subject,
        COUNT(*) OVER() AS total_count, SUM(n.read_at IS NULL) OVER() AS unread_count
    FROM ticket_notifications n JOIN tickets t ON t.id = n.ticket_id JOIN users u ON u.id = p_actor
    WHERE n.user_id = p_actor AND (t.requester_id = p_actor OR u.role IN ('agent','admin','superadmin'))
      AND (n.staff_only = 0 OR u.role IN ('agent','admin','superadmin'))
    ORDER BY n.id DESC LIMIT 25 OFFSET v_offset;
END
