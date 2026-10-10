CREATE PROCEDURE TicketCategories(IN p_actor BIGINT, IN p_all BOOLEAN)
BEGIN
    SELECT c.slug, c.name, c.active, c.default_assignee_id, u.name AS assignee_name
    FROM ticket_categories c LEFT JOIN users u ON u.id = c.default_assignee_id
    WHERE c.active = 1 OR (p_all = 1 AND EXISTS (SELECT 1 FROM users WHERE id = p_actor AND role = 'admin'))
    ORDER BY c.name;
END
