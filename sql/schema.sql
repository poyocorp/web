-- SQL schema for Stuff site
-- Run this on your MySQL/MariaDB server to create the database objects.

-- Example: CREATE DATABASE poyoweb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE poyoweb;

-- Admins table stores the admin username and bcrypt hash
CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Items table for the Stuff page
CREATE TABLE IF NOT EXISTS items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  username VARCHAR(100),
  url VARCHAR(2000),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
