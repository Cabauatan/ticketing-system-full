CREATE PROCEDURE TicketReopen(IN p_actor BIGINT, IN p_ticket BIGINT)
BEGIN
    DECLARE v_status VARCHAR(20);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT status INTO v_status FROM tickets WHERE id = p_ticket AND requester_id = p_actor FOR UPDATE;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Ticket not found.';
    END IF;
    IF v_status NOT IN ('resolved','closed') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'Only resolved or closed tickets can be reopened.';
    END IF;
    UPDATE tickets SET status = 'open', version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = p_ticket;
    INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
    VALUES (p_ticket, p_actor, 'reopened', 'Requester reopened this ticket.', UTC_TIMESTAMP());
    INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
    SELECT u.id, t.id, 'The requester reopened this ticket.', 1, UTC_TIMESTAMP()
    FROM tickets t JOIN users u ON (u.id = t.assignee_id OR (t.assignee_id IS NULL AND u.role IN ('admin','superadmin')))
    WHERE t.id = p_ticket AND u.id <> p_actor AND u.role IN ('superadmin','admin','agent');
    COMMIT;
END
