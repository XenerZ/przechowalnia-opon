-- ============================================================================
-- migration_invoice_due.sql
-- Termin płatności faktury. Blokada konta następuje dopiero po 2 dniach od
-- minięcia terminu płatności nieopłaconej faktury.
--
-- Baza prod oakitpuware. Idempotentne (procedura sprawdza information_schema).
-- Wymaga wcześniejszego migration_invoices.sql.
-- ============================================================================

DROP PROCEDURE IF EXISTS _add_invoice_due;
DELIMITER //
CREATE PROCEDURE _add_invoice_due()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME='invoices' AND COLUMN_NAME='due_date') THEN
        ALTER TABLE invoices ADD COLUMN due_date DATE NULL AFTER issued_at;
    END IF;
END //
DELIMITER ;
CALL _add_invoice_due();
DROP PROCEDURE _add_invoice_due;
