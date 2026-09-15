-- Despatch Management System - Database Schema
-- Run this SQL to create all required tables

CREATE DATABASE IF NOT EXISTS despatch_mgmt CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE despatch_mgmt;

-- Vendor Master
CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_code VARCHAR(20) UNIQUE NOT NULL,
    vendor_name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    mobile VARCHAR(20),
    gstin VARCHAR(20),
    pan VARCHAR(15),
    payment_terms VARCHAR(100),
    credit_limit DECIMAL(12,2) DEFAULT 0,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    -- Legacy address (kept for backward compat, mirrored from bill_ fields)
    address TEXT,
    city VARCHAR(50),
    state VARCHAR(50),
    pincode VARCHAR(10),
    country VARCHAR(50) DEFAULT 'India',
    -- Bill-To Address (Invoicing / Legal)
    bill_address  TEXT,
    bill_city     VARCHAR(60),
    bill_state    VARCHAR(60),
    bill_pincode  VARCHAR(10),
    bill_country  VARCHAR(50) DEFAULT 'India',
    bill_gstin    VARCHAR(20),
    -- Ship-To Address (Delivery / Warehouse)
    ship_name     VARCHAR(120),
    ship_address  TEXT,
    ship_city     VARCHAR(60),
    ship_state    VARCHAR(60),
    ship_pincode  VARCHAR(10),
    ship_country  VARCHAR(50) DEFAULT 'India',
    ship_gstin    VARCHAR(20),
    ship_contact  VARCHAR(100),
    ship_phone    VARCHAR(20),
    -- Bank
    bank_name VARCHAR(100),
    account_no VARCHAR(30),
    ifsc_code VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Item Master
CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(30) UNIQUE NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    uom VARCHAR(20) NOT NULL,
    hsn_code VARCHAR(20),
    unit_price DECIMAL(12,2) DEFAULT 0,
    gst_rate DECIMAL(5,2) DEFAULT 18.00,
    reorder_level INT DEFAULT 0,
    stock_qty DECIMAL(12,2) DEFAULT 0,
    weight_per_unit DECIMAL(10,3) DEFAULT 0,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Transporter Master
CREATE TABLE IF NOT EXISTS transporters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transporter_code VARCHAR(20) UNIQUE NOT NULL,
    transporter_name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    mobile VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    city VARCHAR(50),
    state VARCHAR(50),
    pincode VARCHAR(10),
    gstin VARCHAR(20),
    pan VARCHAR(15),
    lr_prefix VARCHAR(10),
    vehicle_types VARCHAR(200),
    bank_name VARCHAR(100),
    account_no VARCHAR(30),
    ifsc_code VARCHAR(20),
    rate_per_kg DECIMAL(10,2) DEFAULT 0,
    rate_per_km DECIMAL(10,2) DEFAULT 0,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Purchase Orders
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(30) UNIQUE NOT NULL,
    po_date DATE NOT NULL,
    vendor_id INT NOT NULL,
    delivery_date DATE,
    delivery_address TEXT,
    payment_terms VARCHAR(100),
    currency VARCHAR(10) DEFAULT 'INR',
    subtotal DECIMAL(12,2) DEFAULT 0,
    gst_type VARCHAR(20) DEFAULT 'IGST',       -- IGST | CGST+SGST
    cgst_amount DECIMAL(12,2) DEFAULT 0,
    sgst_amount DECIMAL(12,2) DEFAULT 0,
    igst_amount DECIMAL(12,2) DEFAULT 0,
    gst_amount DECIMAL(12,2) DEFAULT 0,        -- total GST (cgst+sgst or igst)
    discount DECIMAL(12,2) DEFAULT 0,
    total_amount DECIMAL(12,2) DEFAULT 0,
    status ENUM('Draft','Approved','Partially Received','Received','Cancelled') DEFAULT 'Draft',
    remarks TEXT,
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id)
);

-- Purchase Order Items
CREATE TABLE IF NOT EXISTS po_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    item_id INT NOT NULL,
    qty DECIMAL(12,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    taxable_amount DECIMAL(12,2) DEFAULT 0,
    gst_rate DECIMAL(5,2) DEFAULT 0,
    gst_amount DECIMAL(12,2) DEFAULT 0,
    cgst_rate DECIMAL(5,2) DEFAULT 0,
    cgst_amount DECIMAL(12,2) DEFAULT 0,
    sgst_rate DECIMAL(5,2) DEFAULT 0,
    sgst_amount DECIMAL(12,2) DEFAULT 0,
    igst_rate DECIMAL(5,2) DEFAULT 0,
    igst_amount DECIMAL(12,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    total_price DECIMAL(12,2) NOT NULL,
    received_qty DECIMAL(12,2) DEFAULT 0,
    remarks VARCHAR(200),
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id)
);

-- Despatch Orders
CREATE TABLE IF NOT EXISTS despatch_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    despatch_no VARCHAR(30) UNIQUE NOT NULL,
    despatch_date DATE NOT NULL,
    challan_no VARCHAR(30) UNIQUE NOT NULL,
    po_id INT,
    vendor_id INT,
    consignee_name VARCHAR(100) NOT NULL,
    consignee_address TEXT NOT NULL,
    consignee_city VARCHAR(50),
    consignee_state VARCHAR(50),
    consignee_pincode VARCHAR(10),
    consignee_gstin VARCHAR(20),
    consignee_contact VARCHAR(150),
    transporter_id INT,
    lr_number VARCHAR(50),
    lr_date DATE,
    vehicle_no VARCHAR(20),
    driver_name VARCHAR(100),
    driver_mobile VARCHAR(20),
    no_of_packages INT DEFAULT 1,
    total_weight DECIMAL(10,3) DEFAULT 0,
    freight_amount DECIMAL(12,2) DEFAULT 0,
    freight_paid_by ENUM('Consignor','Consignee') DEFAULT 'Consignee',
    delivery_type ENUM('Door Delivery','Godown Delivery','Self Pickup') DEFAULT 'Door Delivery',
    expected_delivery DATE,
    subtotal DECIMAL(12,2) DEFAULT 0,
    gst_amount DECIMAL(12,2) DEFAULT 0,
    total_amount DECIMAL(12,2) DEFAULT 0,
    status ENUM('Draft','Despatched','In Transit','Delivered','Cancelled') DEFAULT 'Draft',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id),
    FOREIGN KEY (transporter_id) REFERENCES transporters(id)
);

-- Despatch Order Items
CREATE TABLE IF NOT EXISTS despatch_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    despatch_id INT NOT NULL,
    item_id INT NOT NULL,
    description VARCHAR(200),
    qty DECIMAL(12,2) NOT NULL,
    uom VARCHAR(20),
    unit_price DECIMAL(12,2) DEFAULT 0,
    gst_rate DECIMAL(5,2) DEFAULT 0,
    gst_amount DECIMAL(12,2) DEFAULT 0,
    total_price DECIMAL(12,2) DEFAULT 0,
    weight DECIMAL(10,3) DEFAULT 0,
    FOREIGN KEY (despatch_id) REFERENCES despatch_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id)
);

-- Transporter Payments
CREATE TABLE IF NOT EXISTS transporter_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_no VARCHAR(30) UNIQUE NOT NULL,
    payment_date DATE NOT NULL,
    transporter_id INT NOT NULL,
    despatch_id INT,
    payment_type ENUM('Advance','Against LR','Full Settlement','Partial') DEFAULT 'Full Settlement',
    amount DECIMAL(12,2) NOT NULL,
    payment_mode ENUM('Cash','Cheque','NEFT','RTGS','UPI','Bank Transfer') DEFAULT 'Bank Transfer',
    reference_no VARCHAR(50),
    bank_name VARCHAR(100),
    remarks TEXT,
    status ENUM('Pending','Paid','Cancelled') DEFAULT 'Paid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transporter_id) REFERENCES transporters(id),
    FOREIGN KEY (despatch_id) REFERENCES despatch_orders(id)
);

-- Company Settings
CREATE TABLE IF NOT EXISTS company_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL,
    address TEXT,
    city VARCHAR(50),
    state VARCHAR(50),
    pincode VARCHAR(10),
    phone VARCHAR(20),
    email VARCHAR(100),
    gstin VARCHAR(20),
    pan VARCHAR(15),
    logo_path VARCHAR(200),
    bank_name VARCHAR(100),
    account_no VARCHAR(30),
    ifsc_code VARCHAR(20)
);

-- Insert default company
INSERT INTO company_settings (company_name, address, city, state, pincode, phone, email, gstin, pan) 
VALUES ('ABC Industries Pvt Ltd', '123, Industrial Area, Phase-1', 'Mumbai', 'Maharashtra', '400001', 
        '022-12345678', 'info@abcindustries.com', '27AABCA1234A1Z5', 'AABCA1234A')
ON DUPLICATE KEY UPDATE company_name = company_name;

-- Sample Vendors
INSERT INTO vendors (vendor_code, vendor_name, contact_person, phone, mobile, email,
    address, city, state, pincode, gstin,
    bill_address, bill_city, bill_state, bill_pincode, bill_country, bill_gstin,
    ship_name, ship_address, ship_city, ship_state, ship_pincode, ship_country) VALUES
('V001', 'XYZ Suppliers Pvt Ltd', 'Raj Sharma', '011-23456789', '9876543210', 'raj@xyz.com',
    '456 Market Road', 'Delhi', 'Delhi', '110001', '07AABCX1234A1Z5',
    '456 Market Road', 'Delhi', 'Delhi', '110001', 'India', '07AABCX1234A1Z5',
    'XYZ Delhi Warehouse', 'Plot 12, Industrial Area', 'Delhi', 'Delhi', '110044', 'India'),
('V002', 'PQR Trading Co', 'Amit Kumar', '022-34567890', '8765432109', 'amit@pqr.com',
    '789 Business Park', 'Pune', 'Maharashtra', '411001', '27AABCP1234A1Z5',
    '789 Business Park', 'Pune', 'Maharashtra', '411001', 'India', '27AABCP1234A1Z5',
    NULL, NULL, NULL, NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE vendor_name = vendor_name;

-- Sample Items
INSERT INTO items (item_code, item_name, category, uom, hsn_code, unit_price, gst_rate, stock_qty) VALUES
('ITM001', 'Steel Pipes 2 inch', 'Raw Material', 'Nos', '7304', 1500.00, 18.00, 100),
('ITM002', 'Copper Wire 6mm', 'Raw Material', 'Kg', '7408', 850.00, 18.00, 250),
('ITM003', 'PVC Fittings Set', 'Components', 'Set', '3917', 350.00, 12.00, 500),
('ITM004', 'Bearing 6205', 'Spare Parts', 'Nos', '8482', 125.00, 18.00, 1000),
('ITM005', 'Lubricant Oil 5L', 'Consumables', 'Can', '2710', 650.00, 18.00, 50)
ON DUPLICATE KEY UPDATE item_name = item_name;

-- Sample Transporters
INSERT INTO transporters (transporter_code, transporter_name, contact_person, mobile, phone, city, state, gstin) VALUES
('T001', 'Fast Logistics Pvt Ltd', 'Suresh Gupta', '9876500001', '011-45678901', 'Delhi', 'Delhi', '07AABCF1234A1Z5'),
('T002', 'Speed Cargo Services', 'Ramesh Patel', '8765400002', '022-56789012', 'Mumbai', 'Maharashtra', '27AABCS1234A1Z5')
ON DUPLICATE KEY UPDATE transporter_name = transporter_name;
