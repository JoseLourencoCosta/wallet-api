CREATE TABLE split_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    public_id CHAR(26) NOT NULL UNIQUE,

    operation_id BIGINT UNSIGNED NOT NULL,

    gross_amount DECIMAL(19,2) NOT NULL,
    supplier_amount DECIMAL(19,2) NOT NULL,
    cbs_amount DECIMAL(19,2) NOT NULL DEFAULT 0.00,
    ibs_amount DECIMAL(19,2) NOT NULL DEFAULT 0.00,

    status VARCHAR(20) NOT NULL DEFAULT 'COMPLETED',

    reference_id VARCHAR(100) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,

    CONSTRAINT fk_split_payments_operation
        FOREIGN KEY (operation_id)
        REFERENCES operations(id),

    CONSTRAINT uq_split_payments_operation
        UNIQUE (operation_id),

    CONSTRAINT chk_split_payments_gross_amount
        CHECK (gross_amount > 0),

    CONSTRAINT chk_split_payments_supplier_amount
        CHECK (supplier_amount >= 0),

    CONSTRAINT chk_split_payments_cbs_amount
        CHECK (cbs_amount >= 0),

    CONSTRAINT chk_split_payments_ibs_amount
        CHECK (ibs_amount >= 0),

    CONSTRAINT chk_split_payments_amount_composition
        CHECK (
            gross_amount =
            supplier_amount + cbs_amount + ibs_amount
        )
);