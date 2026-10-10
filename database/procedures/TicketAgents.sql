CREATE PROCEDURE TicketAgents(IN p_actor BIGINT)
BEGIN
    SELECT id, name FROM users WHERE role IN ('admin','agent')
    AND EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('admin','agent')) ORDER BY name;
END
