CREATE DATABASE IF NOT EXISTS abhidham_registration
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE abhidham_registration;

CREATE TABLE IF NOT EXISTS registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
