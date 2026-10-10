CREATE PROCEDURE TicketUpdate(IN p_actor BIGINT, IN p_id BIGINT, IN p_version INT, IN p_status VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_priority VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_assignee BIGINT)
BEGIN
    CALL TicketUpdateAgents(p_actor, p_id, p_version, p_status, p_priority, IF(p_assignee IS NULL, JSON_ARRAY(), JSON_ARRAY(p_assignee)));
END
