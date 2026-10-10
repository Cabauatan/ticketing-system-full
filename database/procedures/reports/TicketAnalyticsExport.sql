CREATE PROCEDURE TicketAnalyticsExport(IN p_actor BIGINT, IN p_from DATE, IN p_to DATE)
BEGIN
    CALL PermissionRequire(p_actor, 'reports.export');
    CALL TicketAnalytics(p_actor, p_from, p_to);
END
