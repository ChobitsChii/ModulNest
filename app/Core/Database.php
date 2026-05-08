<?php

declare(strict_types=1);

namespace Modulon\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /**
     * Baut eine PDO-Verbindung anhand der Konfiguration auf.
     *
     * @param array{
     *   driver:string,
     *   host:string,
     *   port:string,
     *   name:string,
     *   charset:string,
     *   user:string,
     *   pass:string
     * } $config
     */
    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset'],
        );

        try {
            return new PDO(
                $dsn,
                $config['user'],
                $config['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen.', 0, $exception);
        }
    }
}
