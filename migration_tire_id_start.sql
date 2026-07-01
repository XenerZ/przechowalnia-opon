-- ============================================================================
-- migration_tire_id_start.sql
-- ID pozycji w tire_entries nie ma być nadawane od 1 — start numeracji
-- przesunięty tak, aby nowe wpisy miały dłuższy ciąg cyfr (6-cyfrowe ID).
--
-- Baza prod oakitpuware.
-- Uwaga: to zmiana punktu startu AUTO_INCREMENT — istniejące wiersze
-- zachowują dotychczasowe ID; dotyczy nowo dodawanych pozycji.
-- MySQL nie zejdzie poniżej (max(id)+1), więc ponowne uruchomienie jest bezpieczne.
-- ============================================================================

ALTER TABLE tire_entries AUTO_INCREMENT = 100000;
