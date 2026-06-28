-- ============================================================
-- MIGRACJA PRODUKCYJNA — pełny schemat (multi-tenancy + tickety + support)
--
-- Przeznaczenie: baza w ORYGINALNYM, starym schemacie (przed jakąkolwiek
-- naszą migracją). Konsoliduje migration_v2 + migration_v3 + zmiany z panelu
-- supportu, z poprawkami (brak FK na ticket_messages.author_id oraz
-- impersonation_tokens.created_by — wskazują na support_users, nie users).
--
-- !!! URUCHOM JEDNORAZOWO, PO WYKONANIU PEŁNEJ KOPII ZAPASOWEJ BAZY !!!
-- Zakłada istnienie tabel: users, user_permissions, tire_entries, customers,
-- templates, email_templates, actions, settings. Jeśli któraś nie istnieje,
-- zakomentuj odpowiadające jej linie.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════ CZĘŚĆ A — MULTI-TENANCY ════════════════════

-- 1. Plany
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
    name=VALUES(name), max_tires=VALUES(max_tires),
    has_customers=VALUES(has_customers), has_actions=VALUES(has_actions);

-- 2. Firmy (od razu z polami rozliczeń)
CREATE TABLE IF NOT EXISTS companies (
    id              CHAR(36)      NOT NULL,
    name            VARCHAR(255)  NOT NULL,
    nip             VARCHAR(20)   DEFAULT NULL,
    address         VARCHAR(255)  DEFAULT NULL,
    city            VARCHAR(100)  DEFAULT NULL,
    postal_code     VARCHAR(10)   DEFAULT NULL,
    phone           VARCHAR(50)   DEFAULT NULL,
    email           VARCHAR(255)  NOT NULL,
    plan_id         VARCHAR(50)   NOT NULL DEFAULT 'max',
    trial_ends_at   DATETIME      DEFAULT NULL,
    status          ENUM('pending','active','suspended') DEFAULT 'active',
    notes           TEXT          DEFAULT NULL,
    billing_date    DATE          DEFAULT NULL,
    next_billing_at DATE          DEFAULT NULL,
    created_at      DATETIME      DEFAULT NOW(),
    PRIMARY KEY (id)
);

-- 3. Domyślna firma (stały UUID) — pod nią trafiają istniejące dane
INSERT IGNORE INTO companies (id, name, email, plan_id, status)
VALUES (
    'aaaaaaaa-0000-4000-8000-000000000001',
    'Firma domyslna',
    COALESCE((SELECT email FROM users ORDER BY id LIMIT 1), 'admin@firma.pl'),
    'max', 'active'
);

-- 4. users — rozszerz rolę i dodaj kolumny
ALTER TABLE users MODIFY COLUMN role ENUM('pracownik','admin','super_admin') NOT NULL DEFAULT 'pracownik';
ALTER TABLE users
    ADD COLUMN uuid       CHAR(36) NULL,
    ADD COLUMN company_id CHAR(36) NULL,
    ADD COLUMN status     ENUM('active','inactive','suspended') DEFAULT 'active';
UPDATE users SET uuid = UUID() WHERE uuid IS NULL;
UPDATE users SET company_id = 'aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
ALTER TABLE users
    MODIFY COLUMN uuid       CHAR(36) NOT NULL,
    MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE users ADD UNIQUE KEY uk_users_uuid (uuid);

-- 5. user_permissions: INT → UUID
ALTER TABLE user_permissions ADD COLUMN user_uuid CHAR(36) NULL;
UPDATE user_permissions up JOIN users u ON u.id = up.user_id SET up.user_uuid = u.uuid;
ALTER TABLE user_permissions MODIFY COLUMN user_uuid CHAR(36) NOT NULL;
ALTER TABLE user_permissions DROP COLUMN user_id;
ALTER TABLE user_permissions CHANGE COLUMN user_uuid user_id CHAR(36) NOT NULL;

-- 6. users — zmiana PK z INT na UUID
ALTER TABLE users DROP PRIMARY KEY;
ALTER TABLE users DROP COLUMN id;
ALTER TABLE users CHANGE COLUMN uuid id CHAR(36) NOT NULL;
ALTER TABLE users ADD PRIMARY KEY (id);
ALTER TABLE user_permissions
    ADD CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE users
    ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id);

-- 7. company_id w tabelach danych
ALTER TABLE tire_entries    ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE customers       ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE templates       ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE email_templates ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE actions         ADD COLUMN company_id CHAR(36) NULL;
UPDATE tire_entries    SET company_id='aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
UPDATE customers       SET company_id='aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
UPDATE templates       SET company_id='aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
UPDATE email_templates SET company_id='aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
UPDATE actions         SET company_id='aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id IS NULL;
ALTER TABLE tire_entries    MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE customers       MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE templates       MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE email_templates MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE actions         MODIFY COLUMN company_id CHAR(36) NOT NULL;

-- 8. settings — scoping per firma (PK złożony)
ALTER TABLE settings ADD COLUMN company_id CHAR(36) NOT NULL DEFAULT '';
UPDATE settings SET company_id='aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id='';
ALTER TABLE settings DROP PRIMARY KEY;
ALTER TABLE settings ADD PRIMARY KEY (company_id, `key`);

-- 9. Pools
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
    FOREIGN KEY (pool_id) REFERENCES pools(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 10. Tokeny rejestracji
CREATE TABLE IF NOT EXISTS registration_tokens (
    token      CHAR(64)   NOT NULL PRIMARY KEY,
    company_id CHAR(36)   NOT NULL,
    user_id    CHAR(36)   NOT NULL,
    created_at DATETIME   DEFAULT NOW(),
    used       TINYINT(1) DEFAULT 0,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE
);

-- ════════════════════ CZĘŚĆ B — TICKETY / SUPPORT ════════════════════

-- 11. Konta supportu (osobne od users; potrzebne m.in. do JOINu odpowiedzi supportu)
CREATE TABLE IF NOT EXISTS support_users (
    id         CHAR(36)     NOT NULL PRIMARY KEY,
    username   VARCHAR(100) NOT NULL,
    full_name  VARCHAR(150) NULL,
    email      VARCHAR(255) NOT NULL,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('agent','admin') DEFAULT 'agent',
    created_at DATETIME     DEFAULT NOW(),
    last_login DATETIME     DEFAULT NULL,
    UNIQUE KEY uk_support_username (username),
    UNIQUE KEY uk_support_email    (email)
);

-- 12. Tickety (z czasem pracy i ostatnim agentem)
CREATE TABLE IF NOT EXISTS tickets (
    id            INT          AUTO_INCREMENT PRIMARY KEY,
    company_id    CHAR(36)     NOT NULL,
    user_id       CHAR(36)     NOT NULL,
    subject       VARCHAR(255) NOT NULL,
    status        ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    priority      ENUM('low','normal','high','urgent')           DEFAULT 'normal',
    work_seconds  INT          NOT NULL DEFAULT 0,
    last_agent_id CHAR(36)     NULL,
    created_at    DATETIME     DEFAULT NOW(),
    updated_at    DATETIME     DEFAULT NOW() ON UPDATE NOW(),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE
);

-- 13. Wiadomości ticketów (author_id wskazuje users LUB support_users — bez FK na author_id)
CREATE TABLE IF NOT EXISTS ticket_messages (
    id         INT        AUTO_INCREMENT PRIMARY KEY,
    ticket_id  INT        NOT NULL,
    author_id  CHAR(36)   NOT NULL,
    is_support TINYINT(1) DEFAULT 0,
    message    TEXT       NOT NULL,
    created_at DATETIME   DEFAULT NOW(),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);

-- 14. Tokeny impersonacji (created_by = support_users — bez FK na created_by)
CREATE TABLE IF NOT EXISTS impersonation_tokens (
    token          CHAR(64)   NOT NULL PRIMARY KEY,
    target_user_id CHAR(36)   NOT NULL,
    created_by     CHAR(36)   NOT NULL,
    created_at     DATETIME   DEFAULT NOW(),
    used           TINYINT(1) DEFAULT 0,
    expires_at     DATETIME   NOT NULL,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 15. Obecność na tickecie (kto aktualnie pracuje)
CREATE TABLE IF NOT EXISTS ticket_presence (
    ticket_id  INT      NOT NULL,
    agent_id   CHAR(36) NOT NULL,
    started_at DATETIME NOT NULL,
    last_seen  DATETIME NOT NULL,
    PRIMARY KEY (ticket_id, agent_id)
);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- WERYFIKACJA:
--   SELECT id, name, status FROM companies;
--   SELECT id, username, company_id, status, role FROM users;
--   SHOW TABLES LIKE 'ticket%';
--   SHOW TABLES LIKE 'pool%';
--   DESCRIBE tire_entries;   -- czy jest company_id NOT NULL
-- ============================================================
