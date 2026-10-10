CREATE PROCEDURE TicketReportExport(IN p_actor BIGINT, IN p_days INT)
BEGIN
    CALL PermissionRequire(p_actor, 'reports.export');
    CALL TicketReport(p_actor, p_days);
END
