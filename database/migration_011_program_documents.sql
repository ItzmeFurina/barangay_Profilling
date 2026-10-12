-- Migration: adds Employed / Student eligibility flags to program
-- criteria (mirroring the census flags added in migration_010), plus a
-- program_documents table so the admin can configure which documents
-- residents must upload when applying for a program.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

ALTER TABLE program_criteria
    ADD COLUMN requires_employed BOOLEAN NOT NULL DEFAULT FALSE AFTER requires_unemployed,
    ADD COLUMN requires_student  BOOLEAN NOT NULL DEFAULT FALSE AFTER requires_employed;

CREATE TABLE program_documents (
    document_id    INT AUTO_INCREMENT PRIMARY KEY,
    program_id     INT NOT NULL,
    label          VARCHAR(150) NOT NULL,
    files_required INT NOT NULL DEFAULT 1,
    display_order  INT NOT NULL DEFAULT 0,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE
) ENGINE=InnoDB;
