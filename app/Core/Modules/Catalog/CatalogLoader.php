<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use DateTimeImmutable;
use RuntimeException;

final class CatalogLoader
{
    /** @param null|callable():DateTimeImmutable $clock */
    public function __construct(
        private readonly CatalogTrustStore $trust,
        private readonly CatalogCache $cache,
        private readonly mixed $clock = null,
        private readonly CatalogSchemaValidator $schema = new CatalogSchemaValidator(),
    ) {
    }

    public function refresh(CatalogSourceInterface $source): CatalogSnapshot
    {
        $rootJson = $source->read('catalog/v1/root.json', 1048576);
        $root = $this->schema->root($rootJson);
        $signature = $this->schema->signature($source->read('catalog/v1/root.json.sig', 4096));
        $keys = [];
        foreach ($root['signing_keys'] as $key) {
            $keys[$key['key_id']] = $key;
        }
        $rootKey = $keys[$signature['key_id']] ?? null;
        if ($rootKey === null) {
            throw new RuntimeException('Root-Signaturschlüssel fehlt in den Metadaten.');
        }
        if (!$this->trust->isRootKeyAllowed($signature['key_id'])) {
            throw new RuntimeException('Signaturschlüssel ist nicht als Katalog-Root autorisiert.');
        }
        (new Ed25519Verifier($this->trust))->verify(
            $rootJson,
            $signature['key_id'],
            $signature['signature'],
            $rootKey['fingerprint'],
        );

        $now = is_callable($this->clock) ? ($this->clock)() : new DateTimeImmutable('now');
        if (new DateTimeImmutable($root['expires_at']) <= $now) {
            throw new RuntimeException('Katalog ist abgelaufen.');
        }
        $old = $this->cache->load($source->id());
        if ($old !== null) {
            $oldSequence = (int) $old->root['sequence'];
            $newSequence = (int) $root['sequence'];
            if ($newSequence < $oldSequence
                || ($newSequence === $oldSequence
                    && !hash_equals(hash('sha256', json_encode($old->root)), hash('sha256', json_encode($root))))
            ) {
                throw new RuntimeException('Katalog-Replay oder Sequence-Konflikt erkannt.');
            }
        }

        $modules = [];
        foreach ($root['modules'] as $reference) {
            $json = $source->read($reference['path'], 1048576);
            if (!hash_equals($reference['sha256'], hash('sha256', $json))) {
                throw new RuntimeException("Modulindex-Hash stimmt nicht: {$reference['id']}");
            }
            $module = $this->schema->module($json);
            if ($module['id'] !== $reference['id']) {
                throw new RuntimeException('Modulindex-ID stimmt nicht mit Root überein.');
            }
            foreach ($module['releases'] as $release) {
                $releaseKey = $keys[$release['signing_key_id']] ?? null;
                if ($releaseKey === null
                    || !hash_equals($releaseKey['fingerprint'], $release['signing_fingerprint'])
                ) {
                    throw new RuntimeException('Release-Signaturschlüssel ist nicht durch den Katalog-Root autorisiert.');
                }
            }
            $modules[$module['id']] = $module;
        }

        $snapshot = new CatalogSnapshot($source->id(), $root, $modules);
        $this->cache->store($snapshot);

        return $snapshot;
    }

    public function refreshOrLastKnownGood(CatalogSourceInterface $source): CatalogSnapshot
    {
        try {
            return $this->refresh($source);
        } catch (\Throwable $exception) {
            $old = $this->cache->load($source->id());
            if ($old === null) {
                throw $exception;
            }

            return new CatalogSnapshot(
                $old->sourceId,
                $old->root,
                $old->modules,
                true,
                'Katalogaktualisierung fehlgeschlagen; letzter gültiger Stand wird verwendet: ' . $exception->getMessage(),
            );
        }
    }

    public function verifyPackage(CatalogSourceInterface $source, array $release): string
    {
        $package = $release['package'];
        $bytes = $source->read($package['location'], min(268435456, (int) $package['size'] + 1));
        if (strlen($bytes) !== (int) $package['size']
            || !hash_equals($package['sha256'], hash('sha256', $bytes))
        ) {
            throw new RuntimeException('Katalogpaket-Größe oder SHA-256 stimmt nicht.');
        }
        (new Ed25519Verifier($this->trust))->verify(
            $bytes,
            $release['signing_key_id'],
            $release['signature'],
            $release['signing_fingerprint'],
        );

        return $bytes;
    }
}
