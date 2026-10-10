CREATE PROCEDURE TicketEscalate()
BEGIN
    DECLARE v_done BOOLEAN DEFAULT FALSE;
    DECLARE v_ticket BIGINT;
    DECLARE v_due DATETIME;
    DECLARE v_count INT DEFAULT 0;
    DECLARE overdue CURSOR FOR
        SELECT t.id, t.due_at FROM tickets t
        WHERE t.status NOT IN ('resolved','closed') AND t.due_at < UTC_TIMESTAMP()
          AND NOT EXISTS (SELECT 1 FROM ticket_escalations e WHERE e.ticket_id = t.id AND e.due_at = t.due_at)
        ORDER BY t.due_at, t.id LIMIT 500;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    OPEN overdue;
    processing: LOOP
        FETCH overdue INTO v_ticket, v_due;
        IF v_done THEN LEAVE processing; END IF;
        INSERT IGNORE INTO ticket_escalations (ticket_id, due_at, created_at) VALUES (v_ticket, v_due, UTC_TIMESTAMP());
        IF ROW_COUNT() = 1 THEN
            INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
            SELECT u.id, v_ticket, 'SLA deadline breached. Please review this ticket.', 1, UTC_TIMESTAMP()
            FROM users u JOIN tickets t ON t.id = v_ticket
            WHERE u.role IN ('admin','superadmin') OR (EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = u.id) AND u.role = 'agent');
            INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
            VALUES (v_ticket, NULL, 'sla_breached', 'SLA deadline breached; support team notified.', UTC_TIMESTAMP());
            SET v_count = v_count + 1;
        END IF;
    END LOOP;
    CLOSE overdue;
    COMMIT;
    SELECT v_count AS processed;
END
