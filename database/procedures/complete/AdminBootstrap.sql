CREATE PROCEDURE AdminBootstrap(IN p_email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_verify BOOLEAN)
BEGIN
    DECLARE v_lock INT;
    DECLARE v_user BIGINT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT id INTO v_lock FROM ticket_admin_lock WHERE id = 1 FOR UPDATE;
    IF EXISTS (SELECT 1 FROM users WHERE role = 'admin') THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50004, MESSAGE_TEXT = 'An administrator already exists.';
    END IF;
    SELECT id INTO v_user FROM users WHERE email = p_email FOR UPDATE;
    IF v_user IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 50003, MESSAGE_TEXT = 'Register this email first.';
    END IF;
    UPDATE users SET role = 'admin', email_verified_at = IF(p_verify, COALESCE(email_verified_at, UTC_TIMESTAMP()), email_verified_at), updated_at = UTC_TIMESTAMP() WHERE id = v_user;
    INSERT INTO admin_audit (actor_id, action, detail, created_at) VALUES (v_user, 'admin_bootstrap', 'First administrator configured by a local operator', UTC_TIMESTAMP());
    COMMIT;
END
