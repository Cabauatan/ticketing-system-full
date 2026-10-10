CREATE PROCEDURE TicketAttachmentGet(IN p_actor BIGINT, IN p_ticket BIGINT, IN p_attachment BIGINT)
BEGIN
    CALL PermissionRequire(p_actor, 'attachments.download');
    IF NOT EXISTS (
        SELECT 1 FROM ticket_attachments a JOIN tickets t ON t.id = a.ticket_id JOIN users u ON u.id = p_actor
        WHERE a.id = p_attachment AND t.id = p_ticket AND (t.requester_id = p_actor
            OR u.role IN ('superadmin','admin','dispatcher')
            OR t.assignee_id = p_actor
            OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = p_actor))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Attachment not found.';
    END IF;
    SELECT filename, path FROM ticket_attachments WHERE id = p_attachment AND ticket_id = p_ticket;
END
