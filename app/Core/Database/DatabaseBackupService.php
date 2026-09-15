<?php

declare(strict_types=1);

namespace Modulon\Core\Database;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final readonly class DatabaseBackupService
{
    public function __construct(
        private PDO $pdo,
        private string $basePath,
    ) {
    }

    /**
     * Dumps the entire database into a .sql file stream.
     *
     * @param resource $stream
     */
    public function dumpToStream($stream): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Ungültiger Ausgabestream für den Datenbankdump.');
        }

        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isMysql = str_contains(strtolower($driver), 'mysql');

        $header = "-- ========================================================\n"
            . "-- ModulNest Database Backup\n"
            . "-- Treiber: " . $driver . "\n"
            . "-- Erstellt am: " . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "-- ========================================================\n\n";

        if ($isMysql) {
            $header .= "SET FOREIGN_KEY_CHECKS=0;\n"
                . "SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n"
                . "SET NAMES utf8mb4;\n"
                . "SET time_zone = \"+00:00\";\n\n";
        }

        fwrite($stream, $header);

        $tables = $this->getTables($isMysql);

        foreach ($tables as $table) {
            $this->dumpTable($stream, $table, $isMysql);
        }

        $footer = "\n-- ========================================================\n";
        if ($isMysql) {
            $footer .= "SET FOREIGN_KEY_CHECKS=1;\n";
        }
        $footer .= "-- Ende des ModulNest Datenbankdumps\n"
            . "-- ========================================================\n";

        fwrite($stream, $footer);
    }

    /**
     * Dumps the database into a compressed ZIP file containing a database.sql file.
     *
     * @param string $targetZipPath Absolute path where the .zip should be saved.
     * @param string $sqlFilenameInsideZip Name of the .sql file inside the zip.
     * @return string The absolute path of the created zip file.
     */
    public function dumpToZipFile(string $targetZipPath, string $sqlFilenameInsideZip = 'database.sql'): string
    {
        $dir = dirname($targetZipPath);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Zielverzeichnis '{$dir}' konnte nicht erstellt werden.");
        }

        $tempSql = tempnam(sys_get_temp_dir(), 'modulnest_dump_');
        if ($tempSql === false) {
            throw new RuntimeException('Temporäre Dump-Datei konnte nicht initialisiert werden.');
        }

        try {
            $handle = @fopen($tempSql, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Temporäre Dump-Datei konnte nicht geöffnet werden.');
            }

            try {
                $this->dumpToStream($handle);
            } finally {
                fclose($handle);
            }

            $zip = new ZipArchive();
            if ($zip->open($targetZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("ZIP-Datei '{$targetZipPath}' konnte nicht erstellt werden.");
            }

            $zip->addFile($tempSql, $sqlFilenameInsideZip);
            $zip->setCompressionName($sqlFilenameInsideZip, ZipArchive::CM_DEFLATE);
            $zip->close();

            @chmod($targetZipPath, 0600);

            return $targetZipPath;
        } finally {
            if (is_file($tempSql)) {
                @unlink($tempSql);
            }
        }
    }

    /**
     * Creates a temporary ZIP file of the database dump (for downloads) and returns its path.
     */
    public function dumpToTempZip(): string
    {
        $tempZip = tempnam(sys_get_temp_dir(), 'modulnest_db_zip_');
        if ($tempZip === false) {
            throw new RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }
        @unlink($tempZip);
        $zipPath = $tempZip . '.zip';
        $sqlName = 'modulnest-database-' . gmdate('Y-m-d_His') . '.sql';
        return $this->dumpToZipFile($zipPath, $sqlName);
    }

    /**
     * @return list<string>
     */
    private function getTables(bool $isMysql): array
    {
        if ($isMysql) {
            try {
                $stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                $tables = [];
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = (string) $row[0];
                }
                return $tables;
            } catch (Throwable) {
                $stmt = $this->pdo->query("SHOW TABLES");
                $tables = [];
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = (string) $row[0];
                }
                return $tables;
            }
        }

        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tables = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = (string) $row[0];
        }
        return $tables;
    }

    /**
     * @param resource $stream
     */
    private function dumpTable($stream, string $table, bool $isMysql): void
    {
        $safeTable = str_replace('`', '``', $table);

        fwrite($stream, "\n-- --------------------------------------------------------\n");
        fwrite($stream, "-- Tabellenstruktur für `{$safeTable}`\n");
        fwrite($stream, "-- --------------------------------------------------------\n\n");

        if ($isMysql) {
            fwrite($stream, "DROP TABLE IF EXISTS `{$safeTable}`;\n");
            $create = $this->pdo->query("SHOW CREATE TABLE `{$safeTable}`")->fetch(PDO::FETCH_NUM);
            if (is_array($create) && isset($create[1])) {
                fwrite($stream, (string) $create[1] . ";\n\n");
            }
        } else {
            fwrite($stream, "DROP TABLE IF EXISTS \"{$table}\";\n");
            $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$table]);
            $sql = $stmt->fetchColumn();
            if (is_string($sql) && $sql !== '') {
                fwrite($stream, $sql . ";\n\n");
            }
        }

        fwrite($stream, "-- Daten für Tabelle `{$safeTable}`\n");

        $query = $isMysql
            ? "SELECT * FROM `{$safeTable}`"
            : "SELECT * FROM \"{$table}\"";

        if ($isMysql && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            @$this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        $rowsStmt = $this->pdo->query($query);
        $batch = [];
        $batchCount = 0;
        $columnNames = null;

        while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columnNames === null) {
                $columnNames = array_keys($row);
            }

            $escapedValues = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $escapedValues[] = 'NULL';
                } elseif (is_int($val) || is_float($val)) {
                    $escapedValues[] = (string) $val;
                } else {
                    $escapedValues[] = $this->pdo->quote((string) $val);
                }
            }

            $batch[] = '(' . implode(', ', $escapedValues) . ')';
            $batchCount++;

            if ($batchCount >= 100) {
                $colsSql = implode(', ', array_map(static fn(string $c): string => "`" . str_replace('`', '``', $c) . "`", $columnNames));
                $insert = "INSERT INTO `{$safeTable}` ({$colsSql}) VALUES\n" . implode(",\n", $batch) . ";\n";
                fwrite($stream, $insert);
                $batch = [];
                $batchCount = 0;
            }
        }

        if ($batch !== [] && $columnNames !== null) {
            $colsSql = implode(', ', array_map(static fn(string $c): string => "`" . str_replace('`', '``', $c) . "`", $columnNames));
            $insert = "INSERT INTO `{$safeTable}` ({$colsSql}) VALUES\n" . implode(",\n", $batch) . ";\n";
            fwrite($stream, $insert);
        }

        if ($isMysql && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            @$this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        fwrite($stream, "\n");
    }
}
