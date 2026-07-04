-- ============================================================================
-- migration_billing_block.sql
-- Auto-blokada kont zalegających z płatnością. Kolumna suspend_reason rozróżnia
-- blokadę rozliczeniową ('billing') od ręcznego zawieszenia (NULL/'manual'),
-- żeby pokazać użytkownikowi właściwy komunikat.
--
-- Baza prod oakitpuware. Idempotentne.
-- ============================================================================

DROP PROCEDURE IF EXISTS _add_suspend_reason;
DELIMITER //
CREATE PROCEDURE _add_suspend_reason()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME='companies' AND COLUMN_NAME='suspend_reason') THEN
        ALTER TABLE companies ADD COLUMN suspend_reason VARCHAR(30) NULL AFTER status;
    END IF;
END //
DELIMITER ;
CALL _add_suspend_reason();
DROP PROCEDURE _add_suspend_reason;
