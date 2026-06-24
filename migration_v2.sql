-- ============================================================
-- Migration v2: Multi-tenancy, Plans, Pools, UUID user IDs
-- Uruchom JEDNORAZOWO przez phpMyAdmin:
--   Zakładka SQL → wklej całość → kliknij Wykonaj
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── 1. Plany ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plans (
    id            VARCHAR(50)   PRIMARY KEY,
    name          VARCHAR(100)  NOT NULL,
    max_tires     INT           DEFAULT 5,
    has_customers TINYINT(1)    DEFAULT 0,
    has_actions   TINYINT(1)    DEFAULT 0,
    price_monthly DECIMAL(10,2) DEFAULT 0.00,
    is_active     TINYINT(1)    DEFAULT 1,
    sort_order    INT           DEFAULT 0
);

INSERT INTO plans (id, name, max_tires, has_customers, has_actions, price_monthly, sort_order) VALUES
('free', 'Free', 5,    0, 0, 0.00, 1),
('mid',  'Mid',  15,   1, 0, 0.00, 2),
('max',  'Max',  NULL, 1, 1, 0.00, 3)
ON DUPLICATE KEY UPDATE
    name          = VALUES(name),
    max_tires     = VALUES(max_tires),
    has_customers = VALUES(has_customers),
    has_actions   = VALUES(has_actions);

-- ── 2. Firmy ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS companies (
    id            CHAR(36)      NOT NULL,
    name          VARCHAR(255)  NOT NULL,
    nip           VARCHAR(20)   DEFAULT NULL,
    address       VARCHAR(255)  DEFAULT NULL,
    city          VARCHAR(100)  DEFAULT NULL,
    postal_code   VARCHAR(10)   DEFAULT NULL,
    phone         VARCHAR(50)   DEFAULT NULL,
    email         VARCHAR(255)  NOT NULL,
    plan_id       VARCHAR(50)   NOT NULL DEFAULT 'max',
    trial_ends_at DATETIME      DEFAULT NULL,
    status        ENUM('pending','active','suspended') DEFAULT 'active',
    notes         TEXT          DEFAULT NULL,
    created_at    DATETIME      DEFAULT NOW(),
    PRIMARY KEY (id)
);

-- ── 3. Domyślna firma (stały UUID — używany w całej migracji) ─
-- Jeśli tabela była już pusta, wstawi nowy rekord.
INSERT IGNORE INTO companies (id, name, email, plan_id, status)
VALUES (
    'aaaaaaaa-0000-4000-8000-000000000001',
    'Firma domyślna',
    COALESCE((SELECT email FROM users ORDER BY id LIMIT 1), 'admin@firma.pl'),
    'max',
    'active'
);

-- ── 4. Kolumny uuid, company_id, status w tabeli users ───────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS uuid       CHAR(36)     NULL,
    ADD COLUMN IF NOT EXISTS company_id CHAR(36)     NULL,
    ADD COLUMN IF NOT EXISTS status     ENUM('active','inactive','suspended') DEFAULT 'active';

-- Wypełnij UUID-ami (każdy użytkownik dostaje unikalny UUID)
UPDATE users SET uuid = UUID() WHERE uuid IS NULL;

-- Przypisz do domyślnej firmy
UPDATE users
SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001'
WHERE company_id IS NULL;

-- Ustaw NOT NULL
ALTER TABLE users
    MODIFY COLUMN uuid       CHAR(36) NOT NULL,
    MODIFY COLUMN company_id CHAR(36) NOT NULL;

ALTER TABLE users
    ADD UNIQUE KEY IF NOT EXISTS uk_users_uuid (uuid);

-- ── 5. Zmień typ user_id w user_permissions na CHAR(36) UUID ─
-- Dodaj kolumnę tymczasową z UUID
ALTER TABLE user_permissions
    ADD COLUMN IF NOT EXISTS user_uuid CHAR(36) NULL;

UPDATE user_permissions up
    JOIN users u ON u.id = up.user_id
    SET up.user_uuid = u.uuid;

ALTER TABLE user_permissions
    MODIFY COLUMN user_uuid CHAR(36) NOT NULL;

-- ── 6. Zamień PK users z INT na UUID ─────────────────────────
-- (FOREIGN_KEY_CHECKS = 0 pozwala to zrobić bez błędów FK)
ALTER TABLE user_permissions DROP COLUMN user_id;
ALTER TABLE user_permissions CHANGE COLUMN user_uuid user_id CHAR(36) NOT NULL;

ALTER TABLE users DROP PRIMARY KEY;
ALTER TABLE users DROP COLUMN id;
ALTER TABLE users CHANGE COLUMN uuid id CHAR(36) NOT NULL;
ALTER TABLE users ADD PRIMARY KEY (id);

-- Przywróć FK na user_permissions
ALTER TABLE user_permissions
    ADD CONSTRAINT fk_up_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Dodaj FK firmy
ALTER TABLE users
    ADD CONSTRAINT fk_users_company
    FOREIGN KEY (company_id) REFERENCES companies(id);

-- ── 7. company_id w tabelach danych ──────────────────────────
ALTER TABLE tire_entries
    ADD COLUMN IF NOT EXISTS company_id CHAR(36) NULL;
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS company_id CHAR(36) NULL;
ALTER TABLE templates
    ADD COLUMN IF NOT EXISTS company_id CHAR(36) NULL;
ALTER TABLE email_templates
    ADD COLUMN IF NOT EXISTS company_id CHAR(36) NULL;
ALTER TABLE actions
    ADD COLUMN IF NOT EXISTS company_id CHAR(36) NULL;

UPDATE tire_entries    SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
UPDATE customers       SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
UPDATE templates       SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
UPDATE email_templates SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
UPDATE actions         SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;

ALTER TABLE tire_entries    MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE customers       MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE templates       MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE email_templates MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE actions         MODIFY COLUMN company_id CHAR(36) NOT NULL;

-- ── 8. Tabela settings — scoping per firma ────────────────────
ALTER TABLE settings
    ADD COLUMN IF NOT EXISTS company_id CHAR(36) NOT NULL DEFAULT '';

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
-- GOTOWE. Sprawdź wynik:
--   SELECT id, name, status FROM companies;
--   SELECT id, username, company_id, status FROM users;
-- ============================================================
