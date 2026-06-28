-- ============================================================
-- KONTYNUACJA migracji produkcyjnej (po częściowym imporcie)
--
-- Stan wyjściowy (potwierdzony):
--   plans, companies (+ firma domyślna) — istnieją
--   users: ma uuid (NOT NULL), company_id (NOT NULL), status, rola rozszerzona; PK wciąż INT
--   user_permissions: ma user_uuid (NOT NULL); user_id (INT) jeszcze istnieje
--   pozostałe kroki (company_id w danych, settings, pools, tickety, support) — NIE wykonane
--
-- !!! BACKUP przed uruchomieniem !!!
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Dokończenie user_permissions: zdejmij FK, usuń stary user_id, przemianuj ──
ALTER TABLE user_permissions DROP FOREIGN KEY user_permissions_ibfk_1;
ALTER TABLE user_permissions DROP COLUMN user_id;
ALTER TABLE user_permissions CHANGE COLUMN user_uuid user_id CHAR(36) NOT NULL;

-- ── users: PK INT → UUID (zdejmij AUTO_INCREMENT, potem PK) ──
ALTER TABLE users MODIFY COLUMN id INT UNSIGNED NOT NULL;
ALTER TABLE users DROP PRIMARY KEY;
ALTER TABLE users DROP COLUMN id;
ALTER TABLE users CHANGE COLUMN uuid id CHAR(36) NOT NULL;
ALTER TABLE users ADD PRIMARY KEY (id);

ALTER TABLE user_permissions
    ADD CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE users
    ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id);

-- ── company_id w tabelach danych ──
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

-- ── settings — scoping per firma (PK złożony) ──
ALTER TABLE settings ADD COLUMN company_id CHAR(36) NOT NULL DEFAULT '';
UPDATE settings SET company_id='aaaaaaaa-0000-4000-8000-000000000001' WHERE company_id='';
ALTER TABLE settings DROP PRIMARY KEY;
ALTER TABLE settings ADD PRIMARY KEY (company_id, `key`);

-- ── Pools ──
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

-- ── Tokeny rejestracji ──
CREATE TABLE IF NOT EXISTS registration_tokens (
    token      CHAR(64)   NOT NULL PRIMARY KEY,
    company_id CHAR(36)   NOT NULL,
    user_id    CHAR(36)   NOT NULL,
    created_at DATETIME   DEFAULT NOW(),
    used       TINYINT(1) DEFAULT 0,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE
);

-- ── Konta supportu (osobne; potrzebne do JOINu odpowiedzi supportu) ──
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

-- ── Tickety ──
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
CREATE TABLE IF NOT EXISTS ticket_messages (
    id         INT        AUTO_INCREMENT PRIMARY KEY,
    ticket_id  INT        NOT NULL,
    author_id  CHAR(36)   NOT NULL,
    is_support TINYINT(1) DEFAULT 0,
    message    TEXT       NOT NULL,
    created_at DATETIME   DEFAULT NOW(),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS impersonation_tokens (
    token          CHAR(64)   NOT NULL PRIMARY KEY,
    target_user_id CHAR(36)   NOT NULL,
    created_by     CHAR(36)   NOT NULL,
    created_at     DATETIME   DEFAULT NOW(),
    used           TINYINT(1) DEFAULT 0,
    expires_at     DATETIME   NOT NULL,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
);
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
--   SELECT id, username, company_id, status, role FROM users;
--   SHOW COLUMNS FROM user_permissions;     -- user_id = CHAR(36), brak user_uuid
--   SHOW COLUMNS FROM tire_entries;         -- company_id NOT NULL
--   SHOW TABLES LIKE 'ticket%';  SHOW TABLES LIKE 'pool%';
-- ============================================================
