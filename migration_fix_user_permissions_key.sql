-- ============================================================================
-- migration_fix_user_permissions_key.sql
-- KRYTYCZNE: unikat `uq_user_perm` na user_permissions był założony na samej
-- kolumnie `permission`, a powinien być na parze (user_id, permission).
-- Skutek błędu: dane uprawnienie mógł mieć tylko JEDEN użytkownik w całym
-- systemie — nadanie tego samego uprawnienia kolejnym adminom (multi-tenant)
-- było blokowane przez unikat.
--
-- Baza prod oakitpuware.
-- Bezpieczne: przy unikacie na samej `permission` nie istnieją zduplikowane
-- pary (user_id, permission), więc nowy unikat złożony doda się bez konfliktu.
-- ============================================================================

ALTER TABLE user_permissions DROP INDEX uq_user_perm;
ALTER TABLE user_permissions ADD UNIQUE KEY uq_user_perm (user_id, permission);
