CREATE PROCEDURE TicketAgents(IN p_actor BIGINT)
BEGIN
    SELECT id, name FROM users WHERE role IN ('superadmin','admin','agent','dispatcher')
    AND EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('superadmin','admin','agent','dispatcher')) ORDER BY name;
END
