ALTER TABLE accounts
    MODIFY COLUMN user_id BIGINT UNSIGNED NULL,

    ADD COLUMN account_type VARCHAR(20) NOT NULL DEFAULT 'USER'
        AFTER user_id,

    ADD CONSTRAINT chk_accounts_account_type
        CHECK (account_type IN ('USER', 'SYSTEM')),

    ADD CONSTRAINT chk_accounts_owner_by_type
        CHECK (
            (account_type = 'USER' AND user_id IS NOT NULL)
            OR
            (account_type = 'SYSTEM' AND user_id IS NULL)
        );