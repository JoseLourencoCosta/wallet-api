CREATE TABLE operations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(26) NOT NULL UNIQUE,
    type VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL,
    amount DECIMAL(19,2) NOT NULL,
    source_account_id BIGINT UNSIGNED NULL,
    destination_account_id BIGINT UNSIGNED NULL,
    actor_type VARCHAR(20) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    authorization_method VARCHAR(30) NULL,
    reference_id VARCHAR(100) NULL,
    reversal_of_operation_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,

    CONSTRAINT fk_operations_source_account
        FOREIGN KEY (source_account_id)
        REFERENCES accounts(id),

    CONSTRAINT fk_operations_destination_account
        FOREIGN KEY (destination_account_id)
        REFERENCES accounts(id),

    CONSTRAINT fk_operations_reversal
        FOREIGN KEY (reversal_of_operation_id)
        REFERENCES operations(id),

    CONSTRAINT chk_operations_amount
        CHECK (amount > 0)
);

CREATE TABLE ledger_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(26) NOT NULL UNIQUE,
    operation_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    entry_type VARCHAR(10) NOT NULL,
    amount DECIMAL(19,2) NOT NULL,
    balance_before DECIMAL(19,2) NOT NULL,
    balance_after DECIMAL(19,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ledger_operation
        FOREIGN KEY (operation_id)
        REFERENCES operations(id),

    CONSTRAINT fk_ledger_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(id),

    CONSTRAINT chk_ledger_amount
        CHECK (amount > 0)
);