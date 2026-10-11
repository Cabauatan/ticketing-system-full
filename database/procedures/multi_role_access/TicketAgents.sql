CREATE PROCEDURE TicketAgents(IN p_actor BIGINT)
BEGIN
    CALL PermissionRequire(p_actor, 'tickets.view');
    SELECT u.id, u.name FROM users u
    WHERE (
        u.role IN ('superadmin','admin','agent','dispatcher')
        OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = u.id
            AND membership.role IN ('superadmin','admin','agent','dispatcher'))
    ) AND EXISTS (
        SELECT 1 FROM users actor WHERE actor.id = p_actor AND (
            actor.role IN ('superadmin','admin','agent','dispatcher')
            OR EXISTS (SELECT 1 FROM user_roles membership WHERE membership.user_id = actor.id
                AND membership.role IN ('superadmin','admin','agent','dispatcher'))
        )
    )
    ORDER BY u.name, u.id;
END
