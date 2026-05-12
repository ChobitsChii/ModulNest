<?php

declare(strict_types=1);

namespace Modulon\Core\Database;

use PDO;

interface Migration
{
    public function key(): string;

    public function scope(): string;

    public function moduleKey(): ?string;

    public function description(): string;

    public function up(PDO $pdo, SchemaHelper $schema): void;
}
