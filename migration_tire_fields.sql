-- ============================================================================
-- migration_tire_fields.sql
-- Nowe pola wpisu przechowywania opon:
--   * vehicle — dane pojazdu (marka + model) w jednym polu
--   * price   — cena (PLN)
--
-- Baza prod oakitpuware. Idempotentne (sprawdza information_schema).
-- MySQL 5.7 — brak ADD COLUMN IF NOT EXISTS, stąd procedura.
-- ============================================================================

DROP PROCEDURE IF EXISTS _add_tire_fields;
DELIMITER //
CREATE PROCEDURE _add_tire_fields()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tire_entries'
                   AND COLUMN_NAME = 'vehicle') THEN
        ALTER TABLE tire_entries ADD COLUMN vehicle VARCHAR(120) NULL AFTER license_plate;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tire_entries'
                   AND COLUMN_NAME = 'price') THEN
        ALTER TABLE tire_entries ADD COLUMN price DECIMAL(10,2) NULL AFTER vehicle;
    END IF;
END //
DELIMITER ;
CALL _add_tire_fields();
DROP PROCEDURE _add_tire_fields;
