ALTER TABLE operations
    ADD CONSTRAINT uq_operations_reversal_of_operation
        UNIQUE (reversal_of_operation_id);
        