-- Fiber Link Notificações - schema inicial
-- Regra: migrations futuras devem ser ADITIVAS (novas tabelas/colunas com default),
-- para que o rollback de código (deploy/rollback.sh) continue compatível com o banco.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','operador') NOT NULL DEFAULT 'admin',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(100) NOT NULL PRIMARY KEY,
    svalue MEDIUMTEXT NULL,
    encrypted TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sgp_id VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL DEFAULT '',
    document VARCHAR(20) NOT NULL DEFAULT '',
    phone VARCHAR(20) NULL,
    phone_raw VARCHAR(255) NULL,
    email VARCHAR(190) NULL,
    opt_out TINYINT(1) NOT NULL DEFAULT 0,
    wa_exists TINYINT(1) NULL,
    wa_checked_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    sync_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_sgp (sgp_id),
    KEY idx_customers_document (document),
    KEY idx_customers_phone (phone),
    KEY idx_customers_sync (last_synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contracts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    sgp_id VARCHAR(64) NOT NULL,
    plan VARCHAR(190) NULL,
    status ENUM('active','suspended','cancelled','other') NOT NULL DEFAULT 'other',
    status_raw VARCHAR(80) NULL,
    status_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contracts_sgp (sgp_id),
    KEY idx_contracts_customer (customer_id),
    KEY idx_contracts_status (status),
    CONSTRAINT fk_contracts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    contract_id INT UNSIGNED NULL,
    sgp_id VARCHAR(64) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE NOT NULL,
    status ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
    status_raw VARCHAR(80) NULL,
    paid_at DATETIME NULL,
    paid_amount DECIMAL(12,2) NULL,
    barcode VARCHAR(120) NULL,
    pix_code TEXT NULL,
    pdf_url VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoices_sgp (sgp_id),
    KEY idx_invoices_due (status, due_date),
    KEY idx_invoices_customer (customer_id),
    KEY idx_invoices_contract (contract_id),
    CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT chk_invoices_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_templates_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    event ENUM('before_due','due_today','after_due','payment_confirmed','suspended','cancelled','reactivated') NOT NULL,
    offset_days SMALLINT NOT NULL DEFAULT 0,
    template_id INT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    send_start TIME NOT NULL DEFAULT '08:00:00',
    send_end TIME NOT NULL DEFAULT '20:00:00',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rules_code (code),
    KEY idx_rules_event (event, active),
    CONSTRAINT fk_rules_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE RESTRICT,
    CONSTRAINT chk_rules_offset CHECK (offset_days BETWEEN 0 AND 90),
    CONSTRAINT chk_rules_window CHECK (send_start < send_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila de mensagens do WhatsApp. idempotency_key garante que a mesma notificação
-- (regra + fatura/contrato + data) NUNCA seja criada duas vezes.
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(191) NOT NULL,
    rule_id INT UNSIGNED NULL,
    event VARCHAR(40) NOT NULL,
    customer_id INT UNSIGNED NULL,
    contract_id INT UNSIGNED NULL,
    invoice_id INT UNSIGNED NULL,
    destination VARCHAR(20) NOT NULL,
    sent_to VARCHAR(20) NULL,
    body TEXT NOT NULL,
    status ENUM('pending','processing','sent','delivered','read','failed','cancelled','skipped') NOT NULL DEFAULT 'pending',
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    test_code VARCHAR(40) NULL,
    homologation TINYINT(1) NOT NULL DEFAULT 0,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL,
    locked_by VARCHAR(64) NULL,
    locked_at DATETIME NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    provider_message_id VARCHAR(128) NULL,
    last_error VARCHAR(500) NULL,
    status_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_messages_idempotency (idempotency_key),
    KEY idx_messages_queue (status, available_at),
    KEY idx_messages_locked (locked_by),
    KEY idx_messages_provider (provider_message_id),
    KEY idx_messages_customer (customer_id),
    KEY idx_messages_invoice (invoice_id),
    KEY idx_messages_created (created_at),
    KEY idx_messages_sent (sent_at),
    CONSTRAINT fk_messages_rule FOREIGN KEY (rule_id) REFERENCES rules(id) ON DELETE SET NULL,
    CONSTRAINT fk_messages_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_messages_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_messages_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source ENUM('sgp','whatsapp') NOT NULL,
    dedupe_key CHAR(64) NOT NULL,
    event_type VARCHAR(80) NULL,
    status ENUM('received','processed','duplicate','ignored','rejected','error') NOT NULL DEFAULT 'received',
    http_status SMALLINT NOT NULL DEFAULT 200,
    ip VARCHAR(45) NULL,
    payload MEDIUMTEXT NULL,
    error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_webhook_dedupe (source, dedupe_key),
    KEY idx_webhook_created (created_at),
    KEY idx_webhook_status (source, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category ENUM('app','sgp','whatsapp','worker','scheduler','webhooks','auth','deploy') NOT NULL DEFAULT 'app',
    level ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
    message VARCHAR(1000) NOT NULL,
    context MEDIUMTEXT NULL,
    user_id INT UNSIGNED NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME(3) NOT NULL,
    KEY idx_logs_category (category, created_at),
    KEY idx_logs_level (level, created_at),
    KEY idx_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity VARCHAR(60) NULL,
    entity_id VARCHAR(64) NULL,
    details TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    KEY idx_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_tasks (
    name VARCHAR(60) NOT NULL PRIMARY KEY,
    description VARCHAR(190) NOT NULL DEFAULT '',
    interval_seconds INT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    next_run_at DATETIME NULL,
    last_started_at DATETIME NULL,
    last_finished_at DATETIME NULL,
    last_status ENUM('ok','error','running','skipped') NULL,
    last_duration_ms INT UNSIGNED NULL,
    last_message VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS heartbeats (
    component VARCHAR(40) NOT NULL PRIMARY KEY,
    host VARCHAR(120) NULL,
    pid INT UNSIGNED NULL,
    info VARCHAR(255) NULL,
    beat_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locks (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    owner VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_locks_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_login_ip (ip, created_at),
    KEY idx_login_email (email_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarefas administrativas solicitadas pelo painel e executadas pelo agente root (deploy/agent.sh).
-- A web NUNCA executa comandos: apenas registra o pedido, com tipo restrito a esta lista.
CREATE TABLE IF NOT EXISTS system_jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type ENUM('check_update','update','backup','health') NOT NULL,
    status ENUM('queued','running','success','failed','cancelled') NOT NULL DEFAULT 'queued',
    requested_by INT UNSIGNED NULL,
    requested_ip VARCHAR(45) NULL,
    log_file VARCHAR(255) NULL,
    result VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    KEY idx_jobs_status (status, created_at),
    CONSTRAINT fk_jobs_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(40) NOT NULL,
    release_name VARCHAR(120) NOT NULL,
    previous_release VARCHAR(120) NULL,
    kind ENUM('install','deploy','rollback','restore') NOT NULL DEFAULT 'deploy',
    status ENUM('success','failed','rolled_back') NOT NULL,
    backup_file VARCHAR(255) NULL,
    git_ref VARCHAR(120) NULL,
    duration_s INT UNSIGNED NULL,
    message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_deployments_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_checks (
    code VARCHAR(40) NOT NULL PRIMARY KEY,
    passed TINYINT(1) NOT NULL DEFAULT 0,
    details VARCHAR(500) NULL,
    checked_at DATETIME NULL,
    checked_by INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('running','ok','partial','error') NOT NULL DEFAULT 'running',
    customers_checked INT UNSIGNED NOT NULL DEFAULT 0,
    invoices_upserted INT UNSIGNED NOT NULL DEFAULT 0,
    events_created INT UNSIGNED NOT NULL DEFAULT 0,
    errors INT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(500) NULL,
    KEY idx_sync_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
