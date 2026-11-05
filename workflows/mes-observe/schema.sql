-- Hypothetical MySQL 8.x schema. Apply manually to a disposable mes_demo database.
-- Never executed by the observer. Existing applications may adapt the record mapping.
CREATE TABLE mes_production_orders (
    unit_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(64) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    CHECK (row_version > 0)
) ENGINE=InnoDB;

CREATE TABLE mes_quality_checks (
    unit_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(64) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    CHECK (row_version > 0)
) ENGINE=InnoDB;

-- Example producer statements, deliberately not executed here:
-- INSERT INTO mes_production_orders (unit_id, row_version, status)
-- VALUES ('UNIT-00042', 1, 'released')
-- ON DUPLICATE KEY UPDATE row_version = row_version + 1, status = 'released';
--
-- INSERT INTO mes_quality_checks (unit_id, row_version, status)
-- VALUES ('UNIT-00042', 1, 'passed')
-- ON DUPLICATE KEY UPDATE row_version = row_version + 1, status = 'passed';
