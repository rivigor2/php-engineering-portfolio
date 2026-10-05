<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Infrastructure\SQLite;

use PDO;
use RuntimeException;

final class ConnectionFactory
{
    public static function file(string $path): PDO
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create database directory "%s".', $directory));
        }

        return self::configure(new PDO('sqlite:' . $path));
    }

    public static function memory(): PDO
    {
        return self::configure(new PDO('sqlite::memory:'));
    }

    private static function configure(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }
}

