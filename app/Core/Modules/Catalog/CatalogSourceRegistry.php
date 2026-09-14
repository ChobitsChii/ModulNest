<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Stable Core API and sole runtime write path for persisted catalog sources. */
final class CatalogSourceRegistry
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->pdo->query(
            'SELECT * FROM catalog_sources ORDER BY priority DESC, is_official DESC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function enabled(): array
    {
        return array_values(array_filter($this->list(), static fn (array $source): bool => $source['enabled']));
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $id = $this->id($id);
        $statement = $this->pdo->prepare('SELECT * FROM catalog_sources WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string,string> $trustedKeys key ID => base64 Ed25519 public key
     * @param list<string> $rootKeyIds
     * @return array<string,mixed>
     */
    public function add(
        string $id,
        string $name,
        string $sourceType,
        string $location,
        bool $enabled,
        int $priority,
        array $trustedKeys,
        array $rootKeyIds,
        string $actor = 'core-api',
    ): array {
        $values = $this->validated($id, $name, $sourceType, $location, $priority, $trustedKeys, $rootKeyIds, $actor);
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO catalog_sources
                    (id,name,source_type,location,location_hash,enabled,priority,trusted_keys_json,root_key_ids_json,is_official,created_by,updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,0,?,?)'
            );
            $statement->execute([
                $values['id'], $values['name'], $values['source_type'], $values['location'],
                hash('sha256', $values['location']), $enabled ? 1 : 0, $values['priority'],
                $values['trusted_keys_json'], $values['root_key_ids_json'], $values['actor'], $values['actor'],
            ]);
        } catch (\PDOException $error) {
            throw new RuntimeException('Katalogquellen-ID, Name oder Ziel ist bereits registriert.', 0, $error);
        }
        return $this->required($values['id']);
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(string $id, array $changes, string $actor = 'core-api'): array
    {
        $current = $this->required($id);
        $unknown = array_diff(array_keys($changes), ['name', 'source_type', 'location', 'priority', 'trusted_keys', 'root_key_ids']);
        if ($unknown !== []) throw new InvalidArgumentException('Unbekanntes Katalogquellenfeld: ' . reset($unknown));
        if ($changes === []) return $current;
        $values = $this->validated(
            $current['id'],
            (string) ($changes['name'] ?? $current['name']),
            (string) ($changes['source_type'] ?? $current['source_type']),
            (string) ($changes['location'] ?? $current['location']),
            (int) ($changes['priority'] ?? $current['priority']),
            array_key_exists('trusted_keys', $changes) ? $this->arrayChange($changes['trusted_keys'], 'trusted_keys') : $current['trusted_keys'],
            array_key_exists('root_key_ids', $changes) ? $this->arrayChange($changes['root_key_ids'], 'root_key_ids') : $current['root_key_ids'],
            $actor,
        );
        try {
            $statement = $this->pdo->prepare(
                'UPDATE catalog_sources SET name=?,source_type=?,location=?,location_hash=?,priority=?,trusted_keys_json=?,root_key_ids_json=?,updated_by=? WHERE id=?'
            );
            $statement->execute([
                $values['name'], $values['source_type'], $values['location'], hash('sha256', $values['location']),
                $values['priority'], $values['trusted_keys_json'], $values['root_key_ids_json'], $values['actor'], $current['id'],
            ]);
        } catch (\PDOException $error) {
            throw new RuntimeException('Katalogquellen-Name oder Ziel ist bereits registriert.', 0, $error);
        }
        return $this->required($current['id']);
    }

    /** @return array<string,mixed> */
    public function enable(string $id, string $actor = 'core-api'): array { return $this->setEnabled($id, true, $actor); }
    /** @return array<string,mixed> */
    public function disable(string $id, string $actor = 'core-api'): array { return $this->setEnabled($id, false, $actor); }

    public function recordRefreshSuccess(string $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE catalog_sources SET last_refresh_at=NOW(6),last_success_at=NOW(6),last_error_at=NULL,last_error_code=NULL,last_error_message=NULL WHERE id=?'
        );
        $statement->execute([$this->id($id)]);
    }

    public function recordRefreshFailure(string $id, string $code, string $message): void
    {
        $code = preg_replace('/[^A-Za-z0-9_.-]/', '_', $code) ?: 'catalog_refresh_failed';
        $statement = $this->pdo->prepare(
            'UPDATE catalog_sources SET last_refresh_at=NOW(6),last_error_at=NOW(6),last_error_code=?,last_error_message=? WHERE id=?'
        );
        $statement->execute([substr($code, 0, 120), substr($message, 0, 500), $this->id($id)]);
    }

    /** @return array<string,mixed> */
    private function setEnabled(string $id, bool $enabled, string $actor): array
    {
        $source = $this->required($id);
        $actor = $this->actor($actor);
        $statement = $this->pdo->prepare('UPDATE catalog_sources SET enabled=?,updated_by=? WHERE id=?');
        $statement->execute([$enabled ? 1 : 0, $actor, $source['id']]);
        return $this->required($source['id']);
    }

    /** @return array<string,mixed> */
    private function required(string $id): array
    {
        $source = $this->get($id);
        if ($source === null) throw new RuntimeException('Katalogquelle wurde nicht gefunden.');
        return $source;
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $keys = json_decode((string) $row['trusted_keys_json'], true, 32, JSON_THROW_ON_ERROR);
        $roots = json_decode((string) $row['root_key_ids_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($keys) || array_is_list($keys) || !is_array($roots) || !array_is_list($roots)) {
            throw new RuntimeException('Persistierte Katalog-Trust-Konfiguration ist ungültig.');
        }
        $row['enabled'] = (int) $row['enabled'] === 1;
        $row['is_official'] = (int) $row['is_official'] === 1;
        $row['priority'] = (int) $row['priority'];
        $row['trusted_keys'] = $keys;
        $row['root_key_ids'] = array_values(array_map('strval', $roots));
        unset($row['trusted_keys_json'], $row['root_key_ids_json'], $row['location_hash']);
        return $row;
    }

    /** @return array<string,mixed> */
    private function validated(string $id, string $name, string $type, string $location, int $priority, array $keys, array $roots, string $actor): array
    {
        $id = $this->id($id);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160 || preg_match('/[\x00-\x1F\x7F]/u', $name)) throw new InvalidArgumentException('Ungültiger Katalogquellenname.');
        $type = strtolower(trim($type));
        if (!in_array($type, ['https', 'local'], true)) throw new InvalidArgumentException('Nicht unterstützter Katalogquellentyp.');
        $location = $type === 'https' ? $this->httpsLocation($location) : $this->localLocation($location);
        if ($priority < -100000 || $priority > 100000) throw new InvalidArgumentException('Katalogquellen-Priorität liegt außerhalb des erlaubten Bereichs.');
        [$keys, $roots] = $this->trust($keys, $roots);
        return [
            'id'=>$id, 'name'=>$name, 'source_type'=>$type, 'location'=>$location, 'priority'=>$priority,
            'trusted_keys_json'=>json_encode($keys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'root_key_ids_json'=>json_encode($roots, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'actor'=>$this->actor($actor),
        ];
    }

    private function id(string $id): string
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-z][a-z0-9.-]{2,119}$/D', $id)) throw new InvalidArgumentException('Ungültige unveränderliche Katalogquellen-ID.');
        return $id;
    }

    private function actor(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '' || strlen($actor) > 120 || !preg_match('/^[A-Za-z0-9@._:-]+$/D', $actor)) throw new InvalidArgumentException('Ungültiger Audit-Akteur.');
        return $actor;
    }

    private function arrayChange(mixed $value, string $field): array
    {
        if (!is_array($value)) throw new InvalidArgumentException('Katalogquellenfeld ' . $field . ' muss ein Array sein.');
        return $value;
    }

    private function httpsLocation(string $location): string
    {
        $location = rtrim(trim($location), '/');
        $parts = parse_url($location);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
        ) throw new InvalidArgumentException('Katalogquelle muss eine öffentliche HTTPS-Basis-URL ohne Zugangsdaten, Query oder Fragment sein.');
        return $location;
    }

    private function localLocation(string $location): string
    {
        $location = rtrim(str_replace('\\', '/', trim($location)), '/');
        if ($location === '' || $location === '/' || !str_starts_with($location, '/') || str_contains($location, "\0")
            || preg_match('#(^|/)\.\.?(/|$)#', $location)
        ) throw new InvalidArgumentException('Lokale Katalogquelle muss ein sicherer absoluter Pfad sein.');
        if (file_exists($location) && (is_link($location) || !is_dir($location) || !is_readable($location))) {
            throw new InvalidArgumentException('Lokale Katalogquelle ist kein lesbares echtes Verzeichnis.');
        }
        return $location;
    }

    /** @return array{0:array<string,string>,1:list<string>} */
    private function trust(array $keys, array $roots): array
    {
        if ($keys === [] || array_is_list($keys) || $roots === [] || !array_is_list($roots)) throw new InvalidArgumentException('Katalogquelle benötigt eine explizite Trust-Key-Bindung.');
        $normalized = [];
        foreach ($keys as $keyId => $encoded) {
            if (!is_string($keyId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,119}$/D', $keyId) || !is_string($encoded)) throw new InvalidArgumentException('Ungültige Katalog-Key-Bindung.');
            $publicKey = base64_decode($encoded, true);
            if (!is_string($publicKey) || strlen($publicKey) !== 32) throw new InvalidArgumentException('Ungültiger Ed25519 Public Key.');
            $normalized[$keyId] = $encoded;
        }
        $rootIds = [];
        foreach ($roots as $root) {
            if (!is_string($root) || !isset($normalized[$root])) throw new InvalidArgumentException('Root-Key ist nicht an diese Katalogquelle gebunden.');
            $rootIds[] = $root;
        }
        return [$normalized, array_values(array_unique($rootIds))];
    }
}
