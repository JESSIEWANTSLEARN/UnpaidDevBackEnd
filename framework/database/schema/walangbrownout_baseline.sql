-- WalangBrownout baseline business schema.
-- Structure only; no production/test data.
-- Executed by Laravel migration 2026_09_01_000000_create_wbo_baseline_schema.php.

CREATE TABLE IF NOT EXISTS WBO_Users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    contact_number VARCHAR(20) NULL,
    street_address VARCHAR(255) NULL,
    barangay VARCHAR(100) NULL,
    city_municipality VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    postal_code VARCHAR(20) NULL,

    password_hash VARCHAR(255) NOT NULL,

    role ENUM(
        'super_admin',
        'Operations_Manager',
        'Purchasing_Manager',
        'Warehouse_Admin',
        'Sales_Manager',
        'Purchasing_Staff',
        'Inventory_Controller',
        'Sales_Staff',
        'User_Admin',
        'System_User'
    ) NOT NULL,

    account_status ENUM(
        'pending_verification',
        'active',
        'disabled'
    ) NOT NULL DEFAULT 'pending_verification',

    email_verified_at DATETIME NULL,
    last_seen_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_users_role (role),
    INDEX idx_users_status (account_status),
    INDEX idx_users_last_seen (last_seen_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_Suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,
    contact_number VARCHAR(20) NULL,
    email VARCHAR(100) NULL,
    address VARCHAR(255) NULL,

    lead_time_days INT NOT NULL DEFAULT 7,

    supplier_status ENUM(
        'ACTIVE',
        'INACTIVE'
    ) NOT NULL DEFAULT 'ACTIVE',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_supplier_status (supplier_status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_Categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_category_active (is_active)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_Products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,

    sku VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,

    category_id INT NULL,
    supplier_id INT NULL,

    abc_class ENUM(
        'A',
        'B',
        'C'
    ) NOT NULL DEFAULT 'C',

    is_seasonal BOOLEAN NOT NULL DEFAULT FALSE,
    is_visible BOOLEAN NOT NULL DEFAULT TRUE,
    is_featured BOOLEAN NOT NULL DEFAULT FALSE,

    unit_cost DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    reorder_point INT UNSIGNED NOT NULL DEFAULT 10,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_product_category
        FOREIGN KEY (category_id)
        REFERENCES WBO_Categories(category_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_wbo_product_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES WBO_Suppliers(supplier_id)
        ON DELETE SET NULL,

    INDEX idx_product_category (category_id),
    INDEX idx_product_supplier (supplier_id),
    INDEX idx_product_abc (abc_class),
    INDEX idx_product_visible (is_visible),
    INDEX idx_product_featured (is_featured)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_ProductImages (
    image_id INT AUTO_INCREMENT PRIMARY KEY,

    product_id INT NOT NULL,

    image_path VARCHAR(255) NULL,
    image_data MEDIUMBLOB NULL,
    mime_type VARCHAR(50) NULL,
    file_size INT UNSIGNED NULL,
    alt_text VARCHAR(150) NULL,

    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INT NOT NULL DEFAULT 0,

    uploaded_by INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_product_image_product
        FOREIGN KEY (product_id)
        REFERENCES WBO_Products(product_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wbo_product_image_uploader
        FOREIGN KEY (uploaded_by)
        REFERENCES WBO_Users(user_id)
        ON DELETE SET NULL,

    INDEX idx_product_image_product (product_id),
    INDEX idx_product_image_primary (is_primary)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_Batches (
    batch_id INT AUTO_INCREMENT PRIMARY KEY,

    product_id INT NOT NULL,
    batch_number VARCHAR(50) NOT NULL,

    quantity_received INT NOT NULL,
    current_quantity INT NOT NULL,

    received_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATE NULL,

    CONSTRAINT fk_wbo_batch_product
        FOREIGN KEY (product_id)
        REFERENCES WBO_Products(product_id)
        ON DELETE RESTRICT,

    UNIQUE KEY unique_product_batch (
        product_id,
        batch_number
    ),

    INDEX idx_batch_product (product_id),
    INDEX idx_batch_expiry (expiry_date)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_Orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,

    customer_user_id INT NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_contact VARCHAR(100) NULL,
    delivery_email VARCHAR(100) NULL,
    delivery_street_address VARCHAR(255) NULL,
    delivery_barangay VARCHAR(100) NULL,
    delivery_city_municipality VARCHAR(100) NULL,
    delivery_province VARCHAR(100) NULL,
    delivery_postal_code VARCHAR(20) NULL,
    delivery_notes VARCHAR(500) NULL,

    order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    status ENUM(
        'PENDING',
        'PROCESSING',
        'FULFILLED',
        'UNFULFILLED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'PENDING',

    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM(
        'CASH_ON_DELIVERY',
        'GCASH',
        'BANK_TRANSFER'
    ) NULL,

    payment_status ENUM(
        'PENDING',
        'AWAITING_VERIFICATION',
        'PAID',
        'FAILED',
        'REFUNDED',
        'CANCELLED'
    ) NULL,

    payment_amount DECIMAL(12,2) NULL,
    payment_reference_number VARCHAR(100) NULL,
    paid_at DATETIME NULL,

    fulfilled_at DATETIME NULL,
    cancelled_at DATETIME NULL,

    CONSTRAINT fk_wbo_order_customer
        FOREIGN KEY (customer_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE RESTRICT,

    INDEX idx_order_customer (customer_user_id),
    INDEX idx_order_status (status),
    INDEX idx_order_date (order_date),
    INDEX idx_order_payment_method (payment_method),
    INDEX idx_order_payment_status (payment_status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_OrderDetails (
    order_detail_id INT AUTO_INCREMENT PRIMARY KEY,

    order_id INT NOT NULL,
    product_id INT NOT NULL,

    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,

    CONSTRAINT fk_wbo_order_detail_order
        FOREIGN KEY (order_id)
        REFERENCES WBO_Orders(order_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wbo_order_detail_product
        FOREIGN KEY (product_id)
        REFERENCES WBO_Products(product_id)
        ON DELETE RESTRICT,

    INDEX idx_order_detail_order (order_id),
    INDEX idx_order_detail_product (product_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_PurchaseOrders (
    po_id INT AUTO_INCREMENT PRIMARY KEY,

    po_number VARCHAR(50) NOT NULL UNIQUE,
    supplier_id INT NOT NULL,

    status ENUM(
        'DRAFT',
        'PENDING_APPROVAL',
        'APPROVED',
        'ORDERED',
        'PARTIALLY_RECEIVED',
        'RECEIVED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'DRAFT',

    created_by_user_id INT NOT NULL,
    approved_by_user_id INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    ordered_at DATETIME NULL,
    received_at DATETIME NULL,
    cancelled_at DATETIME NULL,

    CONSTRAINT fk_wbo_po_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES WBO_Suppliers(supplier_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_wbo_po_creator
        FOREIGN KEY (created_by_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_wbo_po_approver
        FOREIGN KEY (approved_by_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE SET NULL,

    INDEX idx_po_supplier (supplier_id),
    INDEX idx_po_status (status),
    INDEX idx_po_created_by (created_by_user_id),
    INDEX idx_po_approved_by (approved_by_user_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_PurchaseOrderDetails (
    po_detail_id INT AUTO_INCREMENT PRIMARY KEY,

    po_id INT NOT NULL,
    product_id INT NOT NULL,

    quantity_ordered INT NOT NULL,
    quantity_received INT NOT NULL DEFAULT 0,

    unit_cost DECIMAL(10,2) NOT NULL,

    CONSTRAINT fk_wbo_po_detail_po
        FOREIGN KEY (po_id)
        REFERENCES WBO_PurchaseOrders(po_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wbo_po_detail_product
        FOREIGN KEY (product_id)
        REFERENCES WBO_Products(product_id)
        ON DELETE RESTRICT,

    UNIQUE KEY unique_po_product (
        po_id,
        product_id
    ),

    INDEX idx_po_detail_po (po_id),
    INDEX idx_po_detail_product (product_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_Transactions (
    transaction_id BIGINT AUTO_INCREMENT PRIMARY KEY,

    batch_id INT NOT NULL,

    transaction_type ENUM(
        'RECEIVE',
        'SALE',
        'RESERVE',
        'ADJUSTMENT',
        'WRITE_OFF'
    ) NOT NULL,

    quantity_change INT NOT NULL,

    order_id INT NULL,
    purchase_order_id INT NULL,

    reference_note VARCHAR(255) NULL,

    performed_by_user_id INT NOT NULL,

    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_transaction_batch
        FOREIGN KEY (batch_id)
        REFERENCES WBO_Batches(batch_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_wbo_transaction_order
        FOREIGN KEY (order_id)
        REFERENCES WBO_Orders(order_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_wbo_transaction_po
        FOREIGN KEY (purchase_order_id)
        REFERENCES WBO_PurchaseOrders(po_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_wbo_transaction_user
        FOREIGN KEY (performed_by_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE RESTRICT,

    INDEX idx_transaction_batch (batch_id),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_transaction_order (order_id),
    INDEX idx_transaction_po (purchase_order_id),
    INDEX idx_transaction_user (performed_by_user_id),
    INDEX idx_transaction_timestamp (timestamp)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_Notifications (
    notification_id BIGINT AUTO_INCREMENT PRIMARY KEY,

    alert_tier ENUM(
        'Yellow',
        'Orange',
        'Red',
        'Expiry'
    ) NOT NULL,

    title VARCHAR(150) NULL,
    message VARCHAR(500) NULL,

    related_product_id INT NULL,
    related_batch_id INT NULL,

    recipient_user_id INT NOT NULL,

    triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    status ENUM(
        'UNREAD',
        'ACKNOWLEDGED',
        'RESOLVED'
    ) NOT NULL DEFAULT 'UNREAD',

    acknowledged_at DATETIME NULL,
    resolved_at DATETIME NULL,

    CONSTRAINT fk_wbo_notification_product
        FOREIGN KEY (related_product_id)
        REFERENCES WBO_Products(product_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_wbo_notification_batch
        FOREIGN KEY (related_batch_id)
        REFERENCES WBO_Batches(batch_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_wbo_notification_recipient
        FOREIGN KEY (recipient_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE CASCADE,

    INDEX idx_notification_user (recipient_user_id),
    INDEX idx_notification_status (status),
    INDEX idx_notification_tier (alert_tier),
    INDEX idx_notification_triggered (triggered_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_AuditLogs (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NULL,

    action VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_audit_user
        FOREIGN KEY (user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE SET NULL,

    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_SystemSettings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,

    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value LONGTEXT NULL,

    setting_type ENUM(
        'STRING',
        'NUMBER',
        'BOOLEAN',
        'JSON',
        'FILE',
        'SECRET'
    ) NOT NULL DEFAULT 'STRING',

    is_sensitive BOOLEAN NOT NULL DEFAULT FALSE,

    description VARCHAR(255) NULL,
    updated_by_user_id INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_setting_user
        FOREIGN KEY (updated_by_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE SET NULL,

    INDEX idx_setting_type (setting_type),
    INDEX idx_setting_sensitive (is_sensitive)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_TrustedDevices (
    trusted_device_id BIGINT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    token_hash CHAR(64) NOT NULL UNIQUE,

    device_name VARCHAR(150) NULL,
    browser_name VARCHAR(100) NULL,
    operating_system VARCHAR(100) NULL,
    user_agent VARCHAR(500) NULL,

    last_ip_address VARCHAR(45) NULL,
    last_used_at DATETIME NULL,

    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_trusted_device_user
        FOREIGN KEY (user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE CASCADE,

    INDEX idx_trusted_user (user_id),
    INDEX idx_trusted_expiry (expires_at),
    INDEX idx_trusted_revoked (revoked_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_UserSessions (
    session_id VARCHAR(255) PRIMARY KEY,

    user_id INT NOT NULL,

    device_name VARCHAR(150) NULL,
    browser_name VARCHAR(100) NULL,
    operating_system VARCHAR(100) NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,

    logged_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    logged_out_at DATETIME NULL,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_wbo_user_session_user
        FOREIGN KEY (user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE CASCADE,

    INDEX idx_session_user (user_id),
    INDEX idx_session_active (is_active),
    INDEX idx_session_activity (last_activity_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_DataImports (
    import_id BIGINT AUTO_INCREMENT PRIMARY KEY,

    uploaded_by_user_id INT NOT NULL,

    import_type ENUM(
        'PRODUCTS',
        'INVENTORY',
        'SUPPLIERS',
        'PURCHASE_ORDERS',
        'CUSTOMERS',
        'GENERIC'
    ) NOT NULL,

    file_type ENUM(
        'CSV',
        'XLS',
        'XLSX',
        'DOCX',
        'OTHER'
    ) NOT NULL,

    original_filename VARCHAR(255) NOT NULL,
    stored_file_path VARCHAR(255) NOT NULL,

    status ENUM(
        'UPLOADED',
        'VALIDATING',
        'PROCESSING',
        'COMPLETED',
        'PARTIAL',
        'FAILED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'UPLOADED',

    total_rows INT NOT NULL DEFAULT 0,
    successful_rows INT NOT NULL DEFAULT 0,
    failed_rows INT NOT NULL DEFAULT 0,

    started_at DATETIME NULL,
    completed_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_data_import_user
        FOREIGN KEY (uploaded_by_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE RESTRICT,

    INDEX idx_import_user (uploaded_by_user_id),
    INDEX idx_import_type (import_type),
    INDEX idx_import_status (status),
    INDEX idx_import_created (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_DataImportErrors (
    import_error_id BIGINT AUTO_INCREMENT PRIMARY KEY,

    import_id BIGINT NOT NULL,

    `row_number` INT NULL,
    sheet_name VARCHAR(100) NULL,
    field_name VARCHAR(100) NULL,

    raw_value TEXT NULL,
    raw_row_data LONGTEXT NULL,

    error_message VARCHAR(500) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_import_error_import
        FOREIGN KEY (import_id)
        REFERENCES WBO_DataImports(import_id)
        ON DELETE CASCADE,

    INDEX idx_import_error_import (import_id),
    INDEX idx_import_error_row (`row_number`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_Messages (
    message_id BIGINT AUTO_INCREMENT PRIMARY KEY,

    sender_user_id INT NOT NULL,
    recipient_user_id INT NOT NULL,

    subject VARCHAR(150) NOT NULL,
    message_body TEXT NOT NULL,

    status ENUM(
        'UNREAD',
        'READ',
        'ARCHIVED'
    ) NOT NULL DEFAULT 'UNREAD',

    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    archived_at DATETIME NULL,

    CONSTRAINT fk_wbo_message_sender
        FOREIGN KEY (sender_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wbo_message_recipient
        FOREIGN KEY (recipient_user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE CASCADE,

    INDEX idx_message_sender (sender_user_id),
    INDEX idx_message_recipient (recipient_user_id),
    INDEX idx_message_status (status),
    INDEX idx_message_sent (sent_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_UserProfilePhotos (
    user_id INT NOT NULL PRIMARY KEY,

    photo_data MEDIUMBLOB NOT NULL,
    mime_type VARCHAR(50) NOT NULL,
    file_size INT UNSIGNED NOT NULL,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_wbo_user_profile_photo_user
        FOREIGN KEY (user_id)
        REFERENCES WBO_Users(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_NotificationState (
    state_key VARCHAR(190) NOT NULL PRIMARY KEY,
    state_value VARCHAR(100) NULL,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_FAQs (
    faq_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    category VARCHAR(100) NOT NULL DEFAULT 'General',
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,

    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS WBO_TeamMembers (
    team_member_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,
    role VARCHAR(150) NOT NULL,
    description TEXT NULL,

    photo_data MEDIUMBLOB NULL,
    mime_type VARCHAR(50) NULL,
    file_size INT UNSIGNED NULL,

    sort_order INT NOT NULL DEFAULT 0,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(255) NOT NULL PRIMARY KEY,

    user_id BIGINT NULL,

    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,

    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,

    INDEX sessions_user_id_index (user_id),
    INDEX sessions_last_activity_index (last_activity)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
