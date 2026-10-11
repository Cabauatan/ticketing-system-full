CREATE PROCEDURE TicketEscalate()
BEGIN
    DECLARE v_done BOOLEAN DEFAULT FALSE;
    DECLARE v_ticket BIGINT;
    DECLARE v_due DATETIME;
    DECLARE v_created DATETIME;
    DECLARE v_count INT DEFAULT 0;

    DECLARE overdue CURSOR FOR
        SELECT t.id, t.due_at FROM tickets t
        WHERE t.status NOT IN ('resolved','closed') AND t.due_at < UTC_TIMESTAMP()
          AND NOT EXISTS (SELECT 1 FROM ticket_escalations e WHERE e.ticket_id = t.id AND e.due_at = t.due_at)
        ORDER BY t.due_at, t.id LIMIT 500;
    DECLARE stale CURSOR FOR
        SELECT t.id, t.created_at FROM tickets t
        WHERE t.status NOT IN ('resolved','closed') AND t.priority <> 'urgent'
          AND t.created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
        ORDER BY t.created_at, t.id LIMIT 500;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;

    OPEN overdue;
    process_overdue: LOOP
        FETCH overdue INTO v_ticket, v_due;
        IF v_done THEN LEAVE process_overdue; END IF;

        INSERT IGNORE INTO ticket_escalations (ticket_id, due_at, created_at)
        VALUES (v_ticket, v_due, UTC_TIMESTAMP());
        IF ROW_COUNT() = 1 THEN
            INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
            SELECT u.id, v_ticket, 'SLA deadline breached. Please review this ticket.', 1, UTC_TIMESTAMP()
            FROM users u JOIN tickets t ON t.id = v_ticket
            WHERE (
                u.role IN ('admin','superadmin','dispatcher')
                OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = u.id
                    AND membership.role IN ('admin','superadmin','dispatcher'))
            ) OR (
                (u.id = t.assignee_id OR EXISTS (SELECT 1 FROM ticket_assignees ta
                    WHERE ta.ticket_id = t.id AND ta.user_id = u.id))
                AND (u.role IN ('agent','dispatcher','admin','superadmin')
                    OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = u.id
                        AND membership.role IN ('agent','dispatcher','admin','superadmin')))
            );

            INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
            VALUES (v_ticket, NULL, 'sla_breached', 'SLA deadline breached; support team notified.', UTC_TIMESTAMP());
            SET v_count = v_count + 1;
        END IF;
    END LOOP;
    CLOSE overdue;

    SET v_done = FALSE;
    OPEN stale;
    process_stale: LOOP
        FETCH stale INTO v_ticket, v_created;
        IF v_done THEN LEAVE process_stale; END IF;

        SET v_due = DATE_ADD(v_created, INTERVAL 4 HOUR);
        UPDATE tickets
        SET priority = 'urgent', due_at = v_due, version = version + 1, updated_at = UTC_TIMESTAMP()
        WHERE id = v_ticket AND status NOT IN ('resolved','closed') AND priority <> 'urgent'
          AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY);

        IF ROW_COUNT() = 1 THEN
            -- Record the recalculated urgent SLA deadline so the regular SLA
            -- cursor will not emit a second breach for the same auto-escalation.
            INSERT IGNORE INTO ticket_escalations (ticket_id, due_at, created_at)
            VALUES (v_ticket, v_due, UTC_TIMESTAMP());

            INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
            SELECT t.requester_id, t.id,
                   'Your ticket priority was automatically raised to urgent after 7 days without resolution.',
                   0, UTC_TIMESTAMP()
            FROM tickets t WHERE t.id = v_ticket;

            INSERT INTO ticket_notifications (user_id, ticket_id, message, staff_only, created_at)
            SELECT DISTINCT u.id, t.id,
                   'A ticket was automatically raised to urgent after 7 days without resolution.',
                   1, UTC_TIMESTAMP()
            FROM tickets t
            JOIN users u ON u.id <> t.requester_id
            WHERE t.id = v_ticket AND (
                u.role IN ('admin','superadmin','dispatcher')
                OR EXISTS (
                    SELECT 1 FROM user_roles ur
                    WHERE ur.user_id = u.id AND ur.role IN ('admin','superadmin','dispatcher')
                )
                OR (
                    (u.id = t.assignee_id OR EXISTS (
                        SELECT 1 FROM ticket_assignees ta
                        WHERE ta.ticket_id = t.id AND ta.user_id = u.id
                    ))
                    AND (
                        u.role IN ('agent','dispatcher','admin','superadmin')
                        OR EXISTS (
                            SELECT 1 FROM user_roles ur
                            WHERE ur.user_id = u.id AND ur.role IN ('agent','dispatcher','admin','superadmin')
                        )
                    )
                )
            );

            INSERT INTO ticket_events (ticket_id, actor_id, action, detail, created_at)
            VALUES (
                v_ticket, NULL, 'priority_escalated',
                'Priority automatically raised to urgent after 7 days without resolution.',
                UTC_TIMESTAMP()
            );
            SET v_count = v_count + 1;
        END IF;
    END LOOP;
    CLOSE stale;

    COMMIT;
    SELECT v_count AS processed;
END
