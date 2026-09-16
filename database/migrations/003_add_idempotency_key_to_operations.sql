ALTER TABLE operations
    ADD COLUMN idempotency_key VARCHAR(100) NULL
        AFTER reference_id,
    ADD CONSTRAINT uq_operations_idempotency_key
        UNIQUE (idempotency_key);