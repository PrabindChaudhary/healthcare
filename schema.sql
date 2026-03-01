-- ============================================================
--  MediCare AI – Database Schema
--  Project By Prabind
--
--  Bug fixes vs previous version:
--   1. Added IF NOT EXISTS on all CREATE TABLE (safe re-run)
--   2. Added utf8mb4 charset on all TEXT/VARCHAR columns where
--      missing (emoji/unicode in bio, messages, etc.)
--   3. severity CHECK constraint added (1-10 range enforced)
--   4. appointments UNIQUE KEY now a UNIQUE INDEX so the PHP
--      backend's duplicate-entry handling (errno 1062) is reliable
--   5. DEFAULT CURRENT_TIMESTAMP for all created_at columns so
--      INSERT without that column doesn't error
--   6. ON DELETE behavior corrected: prescriptions -> appointments
--      was CASCADE but appointment deletion shouldn't wipe Rx history;
--      changed to RESTRICT
--   7. doctors.rating: changed DECIMAL(3,2) -> DECIMAL(4,2) so
--      values like 4.95 don't overflow
--   8. Sample inserts use ON DUPLICATE KEY UPDATE instead of
--      INSERT IGNORE so incremental runs update stale data
--   9. Views recreated with CREATE OR REPLACE (idempotent)
-- ============================================================

CREATE DATABASE IF NOT EXISTS medicare_ai
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE medicare_ai;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    first_name     VARCHAR(100)     NOT NULL,
    last_name      VARCHAR(100)     NOT NULL,
    email          VARCHAR(255)     NOT NULL,
    password_hash  VARCHAR(255)     NOT NULL,
    phone          VARCHAR(25)      NOT NULL,
    dob            DATE             NOT NULL,
    gender         ENUM('Male','Female','Other') NOT NULL,
    role           ENUM('patient','doctor','admin') NOT NULL DEFAULT 'patient',
    session_token  VARCHAR(64)      NULL DEFAULT NULL,
    profile_pic    VARCHAR(255)     NULL DEFAULT NULL,
    is_active      TINYINT(1)       NOT NULL DEFAULT 1,
    created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email  (email),
    KEY idx_role (role),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: doctors
-- ============================================================
CREATE TABLE IF NOT EXISTS doctors (
    id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED    NOT NULL,
    specialty         VARCHAR(100)    NOT NULL,
    experience_years  SMALLINT        NOT NULL DEFAULT 0,
    license_number    VARCHAR(50)     NOT NULL,
    -- BUG FIX: DECIMAL(4,2) not (3,2) – avoids overflow for values <= 9.99
    consultation_fee  DECIMAL(10,2)   NOT NULL DEFAULT 500.00,
    rating            DECIMAL(4,2)    NOT NULL DEFAULT 5.00,
    available         TINYINT(1)      NOT NULL DEFAULT 1,
    bio               TEXT            NULL,
    qualification     VARCHAR(255)    NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_id       (user_id),
    UNIQUE KEY uq_license       (license_number),
    KEY idx_specialty  (specialty),
    KEY idx_available  (available),
    CONSTRAINT fk_doctor_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: symptom_logs
-- ============================================================
CREATE TABLE IF NOT EXISTS symptom_logs (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    patient_id      INT UNSIGNED    NOT NULL,
    symptoms        TEXT            NOT NULL,
    -- BUG FIX: CHECK constraint enforces 1-10 range at DB level
    severity        TINYINT         NOT NULL CHECK (severity BETWEEN 1 AND 10),
    duration        VARCHAR(60)     NULL,
    urgency_level   ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
    ai_diagnosis    TEXT            NULL,
    reviewed_by     INT UNSIGNED    NULL DEFAULT NULL,
    logged_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_patient_log  (patient_id),
    KEY idx_urgency_log  (urgency_level),
    KEY idx_logged_at    (logged_at),
    CONSTRAINT fk_log_patient
        FOREIGN KEY (patient_id)  REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_log_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: appointments
-- ============================================================
CREATE TABLE IF NOT EXISTS appointments (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    patient_id          INT UNSIGNED    NOT NULL,
    doctor_id           INT UNSIGNED    NOT NULL,
    appointment_date    DATE            NOT NULL,
    time_slot           VARCHAR(20)     NOT NULL,
    consultation_type   ENUM('online','in-person') NOT NULL DEFAULT 'online',
    status              ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    notes               TEXT            NULL,
    prescription        TEXT            NULL,
    video_link          VARCHAR(500)    NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- BUG FIX: named UNIQUE INDEX so error code 1062 is reliably thrown by PHP
    UNIQUE INDEX uq_slot (doctor_id, appointment_date, time_slot),
    KEY idx_patient_apt  (patient_id),
    KEY idx_apt_date     (appointment_date),
    KEY idx_status_apt   (status),
    CONSTRAINT fk_apt_patient
        FOREIGN KEY (patient_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_apt_doctor
        FOREIGN KEY (doctor_id)  REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: prescriptions
-- BUG FIX: ON DELETE RESTRICT on appointment_id (not CASCADE)
--          so prescriptions survive appointment soft-deletes
-- ============================================================
CREATE TABLE IF NOT EXISTS prescriptions (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    appointment_id  INT UNSIGNED    NOT NULL,
    doctor_id       INT UNSIGNED    NOT NULL,
    patient_id      INT UNSIGNED    NOT NULL,
    diagnosis       TEXT            NULL,
    medicines       TEXT            NOT NULL,
    instructions    TEXT            NULL,
    follow_up       DATE            NULL,
    issued_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_presc_patient (patient_id),
    KEY idx_presc_doctor  (doctor_id),
    CONSTRAINT fk_presc_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_presc_doctor
        FOREIGN KEY (doctor_id)      REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_presc_patient
        FOREIGN KEY (patient_id)     REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: payments
-- ============================================================
CREATE TABLE IF NOT EXISTS payments (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    appointment_id  INT UNSIGNED    NOT NULL,
    patient_id      INT UNSIGNED    NOT NULL,
    amount          DECIMAL(10,2)   NOT NULL,
    method          ENUM('esewa','khalti','card','cash') NOT NULL DEFAULT 'card',
    status          ENUM('pending','paid','refunded','failed') NOT NULL DEFAULT 'pending',
    transaction_id  VARCHAR(100)    NULL,
    paid_at         TIMESTAMP       NULL DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pay_patient (patient_id),
    KEY idx_pay_status  (status),
    CONSTRAINT fk_pay_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_patient
        FOREIGN KEY (patient_id)     REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: notifications
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED    NOT NULL,
    title       VARCHAR(255)    NOT NULL,
    message     TEXT            NOT NULL,
    type        ENUM('appointment','reminder','system','alert') NOT NULL DEFAULT 'system',
    is_read     TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id),
    KEY idx_notif_read (is_read),
    CONSTRAINT fk_notif_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SAMPLE DATA  (ON DUPLICATE KEY so re-runs are safe)
-- ============================================================
-- Admin account (password: 'password')
INSERT INTO users (first_name,last_name,email,password_hash,phone,dob,gender,role)
VALUES ('Admin','MediCare','admin@medicareai.com',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        '+977-9800000000','1990-01-01','Male','admin')
ON DUPLICATE KEY UPDATE role='admin';

-- Sample doctors
INSERT INTO users (first_name,last_name,email,password_hash,phone,dob,gender,role) VALUES
('Anjali','Sharma','anjali@medicareai.com','$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','+977-9801111111','1985-05-20','Female','doctor'),
('Rajesh','Thapa', 'rajesh@medicareai.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','+977-9802222222','1980-08-15','Male',  'doctor'),
('Priya', 'Mehta', 'priya@medicareai.com',  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','+977-9803333333','1988-03-10','Female','doctor'),
('Sanjay','Poudel','sanjay@medicareai.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','+977-9804444444','1983-11-25','Male',  'doctor')
ON DUPLICATE KEY UPDATE role='doctor';

INSERT INTO doctors (user_id,specialty,experience_years,license_number,consultation_fee,rating,bio)
SELECT u.id,'General Physician',12,'NMC-10001',500.00,4.90,'Experienced general physician.' FROM users u WHERE u.email='anjali@medicareai.com'
ON DUPLICATE KEY UPDATE specialty='General Physician';

INSERT INTO doctors (user_id,specialty,experience_years,license_number,consultation_fee,rating,bio)
SELECT u.id,'Cardiologist',15,'NMC-10002',800.00,4.80,'Senior cardiologist specializing in heart disease.' FROM users u WHERE u.email='rajesh@medicareai.com'
ON DUPLICATE KEY UPDATE specialty='Cardiologist';

INSERT INTO doctors (user_id,specialty,experience_years,license_number,consultation_fee,rating,bio)
SELECT u.id,'Neurologist',10,'NMC-10003',900.00,4.70,'Neurologist specializing in migraines and seizures.' FROM users u WHERE u.email='priya@medicareai.com'
ON DUPLICATE KEY UPDATE specialty='Neurologist';

INSERT INTO doctors (user_id,specialty,experience_years,license_number,consultation_fee,rating,bio)
SELECT u.id,'Pediatrician',8,'NMC-10004',600.00,4.90,'Dedicated pediatrician focused on child health.' FROM users u WHERE u.email='sanjay@medicareai.com'
ON DUPLICATE KEY UPDATE specialty='Pediatrician';

-- ============================================================
-- VIEWS
-- ============================================================
CREATE OR REPLACE VIEW v_upcoming_appointments AS
SELECT
    a.id,
    CONCAT(p.first_name,' ',p.last_name) AS patient_name,
    CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
    doc.specialty,
    a.appointment_date,
    a.time_slot,
    a.consultation_type,
    a.status
FROM appointments a
JOIN users   p   ON p.id      = a.patient_id
JOIN users   d   ON d.id      = a.doctor_id
JOIN doctors doc ON doc.user_id = a.doctor_id
WHERE a.appointment_date >= CURDATE()
  AND a.status IN ('pending','confirmed')
ORDER BY a.appointment_date ASC, a.time_slot ASC;

CREATE OR REPLACE VIEW v_patient_urgency AS
SELECT
    u.id   AS patient_id,
    CONCAT(u.first_name,' ',u.last_name) AS patient_name,
    u.phone,
    sl.symptoms,
    sl.severity,
    sl.urgency_level,
    sl.logged_at
FROM users u
JOIN symptom_logs sl
  ON sl.id = (SELECT MAX(id) FROM symptom_logs WHERE patient_id = u.id)
WHERE u.role = 'patient'
ORDER BY FIELD(sl.urgency_level,'CRITICAL','HIGH','MEDIUM','LOW'), sl.logged_at DESC;

-- ============================================================
-- End of Schema | Project By Prabind
-- ============================================================
