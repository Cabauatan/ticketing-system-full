CREATE PROCEDURE TicketAttachmentList(IN p_actor BIGINT, IN p_ticket BIGINT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM tickets t JOIN users u ON u.id = p_actor
        WHERE t.id = p_ticket AND (t.requester_id = p_actor OR u.role IN ('superadmin','admin','agent'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Ticket not found.';
    END IF;
    SELECT a.id, a.filename, a.mime_type, a.size, a.created_at, u.name AS uploader
    FROM ticket_attachments a JOIN users u ON u.id = a.user_id
    WHERE a.ticket_id = p_ticket ORDER BY a.id;
END
