-- Base de dados usada pelos testes de integração contra MySQL
CREATE DATABASE IF NOT EXISTS longa_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON longa_testing.* TO 'longa'@'%';
