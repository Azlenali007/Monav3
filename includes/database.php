<?php
/**
 * Mona SMM Panel v2 - Database Connection
 * Strict PDO MySQL 8+ / MariaDB 10.5+ Singleton
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE " . DB_CHARSET . "_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                error_log("Database connection failure: " . $e->getMessage());
                // In production, do not leak raw credentials or DB details
                http_response_code(500);
                die("A database connection error occurred. Please contact the administrator.");
            }
        }

        return self::$instance;
    }
}

/**
 * Global helper function to get PDO instance
 */
function getDB(): PDO {
    return Database::getInstance();
}
