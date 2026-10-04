CREATE TABLE IF NOT EXISTS prefab_logs (
    id VARCHAR(16) NOT NULL PRIMARY KEY,
    action VARCHAR(191) NOT NULL,
    subject_type VARCHAR(64) NOT NULL,
    subject_id VARCHAR(191) NULL,
    actor_id VARCHAR(191) NULL,
    message TEXT NULL,
    changes JSON NULL,
    metadata JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    occurred_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_prefab_logs_action (action),
    INDEX idx_prefab_logs_subject (subject_type, subject_id),
    INDEX idx_prefab_logs_actor (actor_id),
    INDEX idx_prefab_logs_created (created_at)
);
