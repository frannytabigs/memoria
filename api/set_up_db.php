<?php
define('ITS_ME_JUSTTOVERIFY', true);

require_once 'database.php'; // Make sure this path points to your database connection file

$sql = "
-- USERS
CREATE TABLE IF NOT EXISTS users (
    user_id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'Grounds Staff' CHECK (role IN ('Administrator', 'Office Staff', 'Grounds Staff')),
    status VARCHAR(50) NOT NULL DEFAULT 'Unverified' CHECK (status IN ('Verified', 'Unverified')),
    email VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    session_token TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL
);

-- PostgreSQL Partial Indexes (replaces shadow columns)
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_username ON users (username) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_email ON users (email) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_phone ON users (phone_number) WHERE deleted_at IS NULL;

-- INSERT DEFAULT ADMIN
INSERT INTO users (username, role, status, email, phone_number, password_hash, name, session_token, created_at, updated_at) 
VALUES ('admin', 'Administrator', 'Verified', 'admin@gmail.com', '+639293456789', '$2y$12$YtdLBOCGz5.WcnxtHGPBx.eg4yRaijZUBMOYuw9GVAxkp670BXW0y', 'Administrator', NULL, '2026-10-11 01:37:40', '2026-10-11 01:37:57')
ON CONFLICT DO NOTHING;


-- BLOCKS
CREATE TABLE IF NOT EXISTS blocks (
    block_id SERIAL PRIMARY KEY,
    block_name VARCHAR(100) NOT NULL,
    block_type VARCHAR(50) NOT NULL CHECK (block_type IN ('Niche', 'Bone Chamber', 'Lawn/Grounds', 'Unmapped Area', 'Private', 'Mausoleum', 'Mass Grave', 'Cluster', 'Block')),
    image_link TEXT,
    coordinates JSON,
    rows INT NOT NULL DEFAULT 0,
    cols INT NOT NULL DEFAULT 0,
    rotation DECIMAL(10,6) DEFAULT 0.000000,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_block_name ON blocks (block_name) WHERE deleted_at IS NULL;


-- GRAVES
CREATE TABLE IF NOT EXISTS graves (
    grave_id SERIAL PRIMARY KEY,
    block_id INT NOT NULL REFERENCES blocks(block_id) ON DELETE RESTRICT,
    grave_code VARCHAR(50) NOT NULL,
    row_num INT,
    col_num INT,
    status VARCHAR(20) DEFAULT 'Vacant' CHECK (status IN ('Vacant', 'Occupied', 'Other')),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_grave_code ON graves (grave_code) WHERE deleted_at IS NULL;


-- INTERMENTS
CREATE TABLE IF NOT EXISTS interments (
    interment_id SERIAL PRIMARY KEY,
    control_number VARCHAR(100) NOT NULL,
    deceased_name VARCHAR(255) NOT NULL,
    last_known_address TEXT,
    death_certificate VARCHAR(100),
    deceased_date_of_birth DATE,
    deceased_date_of_death DATE,
    deceased_sex VARCHAR(20) DEFAULT 'Unknown' CHECK (deceased_sex IN ('Male', 'Female', 'Unknown')),
    current_grave_id INT NULL REFERENCES graves(grave_id) ON DELETE RESTRICT,
    contact_person_name VARCHAR(255),
    contact_person_phone_number VARCHAR(50),
    contact_person_email VARCHAR(150),
    contact_person_address_barangay TEXT,
    contact_person_address TEXT,
    assistance_type VARCHAR(100) NOT NULL DEFAULT 'Burial' CHECK (assistance_type IN ('Burial', 'Transfer the remains of the late', 'Other')),
    burial_permit_number VARCHAR(100),
    burial_permit_date DATE,
    transfer_permit_number VARCHAR(100),
    transfer_permit_issued_by VARCHAR(150),
    transfer_permit_date DATE,
    exhumation_permit_number VARCHAR(100),
    exhumation_permit_date DATE,
    date_buried DATE,
    date_exhumed DATE,
    burial_clearance_date DATE,
    lease_expiration_date DATE,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Active', 'Inactive')),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_status_grave CHECK (status = 'Active' OR current_grave_id IS NULL)
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_control_number ON interments (control_number) WHERE deleted_at IS NULL;


-- RESERVATIONS
CREATE TABLE IF NOT EXISTS reservations (
    reservation_id SERIAL PRIMARY KEY,
    pending_interment_id INT NOT NULL REFERENCES interments(interment_id) ON DELETE RESTRICT,
    target_grave_id INT NOT NULL REFERENCES graves(grave_id) ON DELETE RESTRICT,
    old_interment_id INT NULL REFERENCES interments(interment_id) ON DELETE RESTRICT,
    old_new_grave_id INT NULL REFERENCES graves(grave_id) ON DELETE RESTRICT,
    old_new_status VARCHAR(20) NULL CHECK (old_new_status IN ('Active','Inactive')),
    old_new_assistance_type VARCHAR(100) NULL CHECK (old_new_assistance_type IN ('Burial','Transfer the remains of the late','Other')),
    old_new_remarks TEXT NULL,
    exhumation_permit_number VARCHAR(100) NULL,
    transfer_permit_number VARCHAR(100) NULL,
    reservation_notes TEXT NULL,
    reservation_state VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (reservation_state IN ('Active','Executed','Cancelled')),
    actioned_at TIMESTAMP NULL,
    actioned_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    old_edited_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_old_pairing CHECK (
        (old_interment_id IS NULL AND old_new_grave_id IS NULL AND old_new_status IS NULL AND old_new_assistance_type IS NULL) OR
        (old_interment_id IS NOT NULL AND old_new_status IS NOT NULL)
    ),
    CONSTRAINT chk_inactive_has_no_grave CHECK (NOT (old_new_status = 'Inactive' AND old_new_grave_id IS NOT NULL)),
    CONSTRAINT chk_old_new_grave_not_target CHECK (old_new_grave_id IS NULL OR old_new_grave_id <> target_grave_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_pending_interment ON reservations(pending_interment_id) WHERE reservation_state = 'Active';
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_target_grave ON reservations(target_grave_id) WHERE reservation_state = 'Active';
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_old_interment ON reservations(old_interment_id) WHERE reservation_state = 'Active';


-- PLACEMENT HISTORY
CREATE TABLE IF NOT EXISTS placement_history (
    history_id SERIAL PRIMARY KEY,
    interment_id INT NOT NULL REFERENCES interments(interment_id) ON DELETE RESTRICT,
    event_type VARCHAR(20) NOT NULL CHECK (event_type IN ('Initial','Move','Removal')),
    from_grave_id INT NULL REFERENCES graves(grave_id) ON DELETE RESTRICT,
    to_grave_id INT NULL REFERENCES graves(grave_id) ON DELETE RESTRICT,
    event_date TIMESTAMP NOT NULL,
    reason TEXT NULL,
    related_interment_id INT NULL REFERENCES interments(interment_id) ON DELETE SET NULL,
    reservation_id INT NULL REFERENCES reservations(reservation_id) ON DELETE SET NULL,
    snapshot_before JSON NULL,
    snapshot_after JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL
);


-- SETTINGS
CREATE TABLE IF NOT EXISTS settings (
    setting_id SERIAL PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_setting_key ON settings (setting_key) WHERE deleted_at IS NULL;

-- INSERT SETTINGS
INSERT INTO settings (setting_id, setting_key, setting_value, description, created_at, updated_at) VALUES
(1, 'logo_1', 'logo_1.png', 'Header Logo 1', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(2, 'logo_2', 'logo_2.png', 'Header Logo 2', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(3, 'cemetery_logo', 'cemetery_logo.png', 'Cemetery Logo', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(4, 'cemetery_mark', 'cemetery_mark.png', 'Cemetery Mark', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(5, 'cemetery_background', 'cemetery_background.png', 'Cemetery Background', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(6, 'qr_code', 'qr_code.png', 'Payment QR Code', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(7, 'dept_name', 'Department of General Services', 'Main Department Name', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(8, 'cemetery_name', 'Mandaue City Public Cemetery', 'Cemetery Name', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(9, 'cemetery_address', 'Barangay Guizo, 710 del Pilar Street, Mandaue, 6014 Cebu', 'Cemetery Physical Address', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(10, 'office_address', '8WFV+PC8 Barangay Centro, Mandaue, Cebu', 'Administrative Office Address', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(11, 'cemetery_google_maps', 'https://www.google.com/maps/embed', 'Google Maps Embed URL', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(12, 'contact_phone', '+63 912 3456 789', 'Contact Hotline / Phone', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(13, 'contact_email', 'memoria@gmail.com', 'Official Email Address', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(14, 'payment_channels_list', '[\"Maya\", \"GoTyme\", \"Maribank\", \"GCash\"]', 'Payment Channels', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(15, 'payment_purposes_list', '[\"Pasuga / Lighting\", \"Burial Fee\"]', 'Purposes of Payment', '2026-10-11 19:05:43', '2026-10-11 19:05:50'),
(31, 'main_color', '#0f172a', 'UI Primary Color', '2026-10-11 19:09:11', '2026-10-11 19:09:20'),
(32, 'people_name_1', 'Ms. Rosemarie U. Chavez', 'Report Signatory 1 Name', '2026-10-11 19:09:11', '2026-10-11 19:09:20'),
(33, 'people_title_1', 'OIC, Department of General Services', 'Report Signatory 1 Title', '2026-10-11 19:09:11', '2026-10-11 19:09:20'),
(34, 'people_name_2', 'Ar. Maria Aileen A. Paragsa', 'Report Signatory 2 Name', '2026-10-11 19:09:11', '2026-10-11 19:09:20'),
(35, 'people_title_2', 'Admin Division Chief', 'Report Signatory 2 Title', '2026-10-11 19:09:11', '2026-10-11 19:09:20'),
(36, 'cemetery_map_images', 'cemetery_map_image.png', 'For the cemetery map backgound image', '2026-11-04 22:50:13', '2026-11-04 22:50:13')
ON CONFLICT DO NOTHING;


-- PAYMENTS
CREATE TABLE IF NOT EXISTS payments (
    payment_id SERIAL PRIMARY KEY,
    reference_number VARCHAR(100) NOT NULL,
    payment_channel VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    deceased_name VARCHAR(255),
    payers_phone_number VARCHAR(20),
    payers_email VARCHAR(255),
    payers_name VARCHAR(255),
    purpose VARCHAR(100) NOT NULL,
    image_link TEXT,
    confirmed_office_staff INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    confirmed_ground_staff INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    remarks_payer TEXT,
    remarks_office TEXT,
    remarks_grounds TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by INT NULL REFERENCES users(user_id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_active_reference_number ON payments (reference_number) WHERE deleted_at IS NULL;

-- INSERT PAYMENT
INSERT INTO payments (reference_number, payment_channel, amount, deceased_name, payers_phone_number, payers_email, payers_name, purpose, image_link) 
VALUES ('0046876781312', 'GCash', 70.00, 'Jean Aika R. Canoy', '+639154570385', 'jeanaikacanoy224@gmail.com', 'John Anthony O. Tabigne', 'Pasuga / Lighting', 'https://lh3.googleusercontent.com/.../test')
ON CONFLICT DO NOTHING;


-- GENERAL INDEXES (Foreign Keys & Statuses)
CREATE INDEX IF NOT EXISTS idx_users_session_token ON users(session_token);
CREATE INDEX IF NOT EXISTS idx_graves_block_id ON graves(block_id);
CREATE INDEX IF NOT EXISTS idx_graves_status ON graves(status);
CREATE INDEX IF NOT EXISTS idx_interments_current_grave_id ON interments(current_grave_id);
CREATE INDEX IF NOT EXISTS idx_interments_status ON interments(status);
CREATE INDEX IF NOT EXISTS idx_reservations_state ON reservations(reservation_state);
CREATE INDEX IF NOT EXISTS idx_history_interment_id ON placement_history(interment_id);
";

try {
    // We execute the statements securely
    $pdo->exec($sql);
    echo "<h1>Database tables created successfully for PostgreSQL!</h1>";
} catch (PDOException $e) {
    echo "<h1>Error creating tables:</h1> <pre>" . $e->getMessage() . "</pre>";
}
