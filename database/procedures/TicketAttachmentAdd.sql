CREATE PROCEDURE TicketAttachmentAdd(IN p_actor BIGINT, IN p_ticket BIGINT, IN p_filename VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_path VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_mime VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_size INT)
BEGIN
    DECLARE v_id BIGINT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF NOT EXISTS (
        SELECT 1 FROM tickets t JOIN users u ON u.id = p_actor
        WHERE t.id = p_ticket AND (t.requester_id = p_actor OR u.role IN ('admin','agent'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Ticket not found.';
    END IF;
    START TRANSACTION;
    INSERT INTO ticket_attachments (ticket_id, user_id, filename, path, mime_type, size, created_at)
    VALUES (p_ticket, p_actor, p_filename, p_path, p_mime, p_size, UTC_TIMESTAMP());
    SET v_id = LAST_INSERT_ID();
    INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
    VALUES (p_ticket, p_actor, 'attachment_added', CONCAT('Attached ', p_filename), UTC_TIMESTAMP());
    UPDATE tickets SET updated_at = UTC_TIMESTAMP() WHERE id = p_ticket;
    COMMIT;
    SELECT v_id AS id;
END
