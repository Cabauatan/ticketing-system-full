CREATE PROCEDURE TicketList(IN p_actor BIGINT, IN p_search VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_status VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_page INT)
BEGIN
    DECLARE v_staff BOOLEAN DEFAULT FALSE;
    DECLARE v_offset INT DEFAULT 0;
    SET v_staff = EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role IN ('admin','agent'));
    SET v_offset = (GREATEST(COALESCE(p_page, 1), 1) - 1) * 20;
    SELECT t.*, u.name AS requester_name, a.name AS assignee_name, COUNT(*) OVER() AS total_count
    FROM tickets t JOIN users u ON u.id = t.requester_id LEFT JOIN users a ON a.id = t.assignee_id
    WHERE (v_staff OR t.requester_id = p_actor)
      AND (p_status = '' OR t.status = p_status)
      AND (p_search = '' OR LOCATE(p_search, t.subject) > 0 OR CAST(t.id AS CHAR) = p_search)
    ORDER BY t.created_at DESC, t.id DESC LIMIT 20 OFFSET v_offset;
END
