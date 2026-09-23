ALTER TABLE accounts
    ADD COLUMN system_role VARCHAR(30) NULL
        AFTER account_type;

UPDATE accounts
SET system_role = 'TAX_CBS'
WHERE account_type = 'SYSTEM'
  AND account_number = '900001';

UPDATE accounts
SET system_role = 'TAX_IBS'
WHERE account_type = 'SYSTEM'
  AND account_number = '900002';

ALTER TABLE accounts
    ADD CONSTRAINT uq_accounts_system_role
        UNIQUE (system_role),

    ADD CONSTRAINT chk_accounts_system_role
        CHECK (
            (
                account_type = 'USER'
                AND system_role IS NULL
            )
            OR
            (
                account_type = 'SYSTEM'
                AND system_role IS NOT NULL
                AND system_role IN ('TAX_CBS', 'TAX_IBS')
            )
        );
        