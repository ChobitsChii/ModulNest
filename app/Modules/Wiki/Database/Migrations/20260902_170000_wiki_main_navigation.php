<?php

declare(strict_types=1);

use Modulon\Core\Database\{Migration, SchemaHelper};

return new class implements Migration {
    public function key(): string { return '20260902_170000_wiki_main_navigation'; }
    public function scope(): string { return 'module'; }
    public function moduleKey(): ?string { return 'wiki'; }
    public function description(): string { return 'Zeigt Wiki als normales Modul in der Hauptnavigation an.'; }
    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        if ($schema->tableExists('modules')) {
            $statement = $pdo->prepare("UPDATE modules SET show_in_header = 1 WHERE route_prefix = :prefix AND handler = 'native'");
            $statement->execute(['prefix' => 'wiki']);
        }
    }
};
