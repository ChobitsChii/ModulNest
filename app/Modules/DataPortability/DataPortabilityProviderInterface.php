<?php

declare(strict_types=1);

namespace Modulon\Modules\DataPortability;

interface DataPortabilityProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function routePrefix(): string;

    public function description(): string;

    public function schemaVersion(): int;

    public function hasFiles(): bool;

    public function sensitivityNote(): string;

    public function supportsReplaceImport(): bool;

    /**
     * @return array<int,string> Erlaubte Oberflächen: admin, user
     */
    public function scopes(): array;

    /**
     * @return array{files:array<string,mixed>,counts?:array<string,int>,warnings?:array<int,string>}
     */
    public function export(int $userId, DataPortabilityFileCollector $files): array;

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $manifestModule
     * @return array{counts:array<string,int>,warnings:array<int,string>,can_import:bool}
     */
    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array;

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $manifestModule
     * @return array{created:int,updated:int,skipped:int,warnings:array<int,string>}
     */
    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array;
}
