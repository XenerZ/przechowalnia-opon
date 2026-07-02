-- ============================================================================
-- migration_fix_main_admins.sql
-- Naprawa GŁÓWNYCH kont firm założonych PRZED poprawką rejestracji
-- (były tworzone jako 'pracownik' bez uprawnień). Nadaje rolę 'admin'
-- i pełne uprawnienia. Główne konto = user.email == companies.email
-- (tak ustawia je rejestracja).
--
-- Baza prod oakitpuware. Idempotentne:
--   - UPDATE ustawia rolę admin (wielokrotne uruchomienie bez skutków ubocznych),
--   - INSERT IGNORE pomija istniejące (user_permissions ma UNIQUE(user_id,permission)).
-- ============================================================================

UPDATE users u
JOIN companies c ON u.company_id = c.id AND u.email = c.email
SET u.role = 'admin';

INSERT IGNORE INTO user_permissions (user_id, permission)
SELECT u.id, p.perm
FROM users u
JOIN companies c ON u.company_id = c.id AND u.email = c.email
CROSS JOIN (
    SELECT 'manage_users'  AS perm
    UNION ALL SELECT 'add_entries'
    UNION ALL SELECT 'edit_entries'
    UNION ALL SELECT 'delete_entries'
) p;

-- Weryfikacja:
--   SELECT u.username, u.email, u.role,
--          GROUP_CONCAT(up.permission) AS perms
--   FROM users u JOIN companies c ON u.company_id=c.id AND u.email=c.email
--   LEFT JOIN user_permissions up ON up.user_id=u.id
--   GROUP BY u.id;
