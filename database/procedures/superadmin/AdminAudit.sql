CREATE PROCEDURE AdminAudit(IN p_actor BIGINT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('admin','superadmin')) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Access denied.';
    END IF;
    SELECT a.id, a.action, a.detail, a.created_at, u.name AS actor
    FROM admin_audit a JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 100;
END
