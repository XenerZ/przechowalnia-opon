-- ============================================================
-- Migration v2 — KONTYNUACJA
-- Uruchom gdy migration_v2.sql wykonał się częściowo.
-- Stan przed uruchomieniem:
--   users.uuid, company_id, status — istnieją
--   user_permissions.user_id — już CHAR(36)
--   companies, plans — istnieją
--   pozostałe kroki — NIE wykonane
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── 6. Zamiana PK users z INT na UUID ────────────────────────
ALTER TABLE users MODIFY COLUMN id INT NOT NULL;
ALTER TABLE users DROP PRIMARY KEY;
ALTER TABLE users DROP COLUMN id;
ALTER TABLE users CHANGE COLUMN uuid id CHAR(36) NOT NULL;
ALTER TABLE users ADD PRIMARY KEY (id);

ALTER TABLE user_permissions
    ADD CONSTRAINT fk_up_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE users
    ADD CONSTRAINT fk_users_company
    FOREIGN KEY (company_id) REFERENCES companies(id);

-- ── 7. company_id w tabelach danych ──────────────────────────
ALTER TABLE tire_entries    ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE customers       ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE templates       ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE email_templates ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE actions         ADD COLUMN company_id CHAR(36) NULL;

UPDATE tire_entries    SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001';
UPDATE customers       SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001';
UPDATE templates       SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001';
UPDATE email_templates SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001';
UPDATE actions         SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001';

ALTER TABLE tire_entries    MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE customers       MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE templates       MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE email_templates MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE actions         MODIFY COLUMN company_id CHAR(36) NOT NULL;

-- ── 8. Tabela settings — scoping per firma ────────────────────
ALTER TABLE settings ADD COLUMN company_id CHAR(36) NOT NULL DEFAULT '';

UPDATE settings
SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001'
WHERE company_id = '';

ALTER TABLE settings DROP PRIMARY KEY;
ALTER TABLE settings ADD PRIMARY KEY (company_id, `key`);

-- ── 9. Pools ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pools (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT         DEFAULT NULL,
    created_at  DATETIME     DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS pool_features (
    pool_id      INT          NOT NULL,
    feature_name VARCHAR(100) NOT NULL,
    PRIMARY KEY (pool_id, feature_name),
    FOREIGN KEY (pool_id) REFERENCES pools(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pool_members (
    pool_id  INT      NOT NULL,
    user_id  CHAR(36) NOT NULL,
    added_at DATETIME DEFAULT NOW(),
    PRIMARY KEY (pool_id, user_id),
    FOREIGN KEY (pool_id) REFERENCES pools(id)  ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE
);

-- ── 10. Tokeny rejestracji ────────────────────────────────────
CREATE TABLE IF NOT EXISTS registration_tokens (
    token      CHAR(64)     NOT NULL,
    company_id CHAR(36)     NOT NULL,
    user_id    CHAR(36)     NOT NULL,
    created_at DATETIME     DEFAULT NOW(),
    used       TINYINT(1)   DEFAULT 0,
    PRIMARY KEY (token),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE
);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- GOTOWE. Sprawdz:
--   SELECT id, username, company_id, status FROM users;
--   SHOW TABLES LIKE 'pool%';
-- ============================================================
