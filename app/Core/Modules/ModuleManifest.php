<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use InvalidArgumentException;

final readonly class ModuleManifest
{
    /** @param array<string,mixed> $raw */
    private function __construct(
        public string $id,
        public string $name,
        public string $description,
        public SemVer $version,
        public string $license,
        public string $routePrefix,
        public string $accessLevel,
        public string $entryClass,
        public string $entryFile,
        /** @var array<string,string> */ public array $psr4,
        public int $schemaVersion,
        /** @var array<string,list<string>> */ public array $ownership,
        /** @var array<string,string> */ public array $dependencies,
        /** @var array<string,string> */ public array $optionalDependencies,
        /** @var array<string,string> */ public array $conflicts,
        /** @var list<string> */ public array $extensions,
        public ?string $migrationsPath,
        public ?string $viewsPath,
        public ?string $assetsPath,
        public array $raw,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        foreach (['manifest_version','id','name','description','version','license','authors','requires','entrypoint','autoload','data','route_prefix','access_level'] as $field) {
            if (!array_key_exists($field, $raw)) throw new InvalidArgumentException("Manifestfeld fehlt: {$field}");
        }
        if ($raw['manifest_version'] !== 2) throw new InvalidArgumentException('Nur manifest_version 2 wird unterstützt.');
        $id = ModuleId::assert(self::string($raw, 'id'));
        $version = SemVer::parse(self::string($raw, 'version'));
        self::nonEmptyText($raw, 'name', 120); self::nonEmptyText($raw, 'description', 500);
        if (!is_array($raw['authors']) || $raw['authors'] === []) throw new InvalidArgumentException('authors muss mindestens einen Eintrag enthalten.');
        foreach ($raw['authors'] as $author) if (!is_array($author) || trim((string)($author['name'] ?? '')) === '') throw new InvalidArgumentException('Jeder Autor benötigt einen Namen.');
        $requires = self::map($raw, 'requires');
        VersionConstraint::parse((string)($requires['core'] ?? throw new InvalidArgumentException('requires.core fehlt.')));
        VersionConstraint::parse((string)($requires['php'] ?? throw new InvalidArgumentException('requires.php fehlt.')));
        $entry = self::map($raw, 'entrypoint');
        $entryClass = trim((string)($entry['class'] ?? ''));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $entryClass)) throw new InvalidArgumentException('entrypoint.class ist ungültig.');
        $entryFile = self::relativePath((string)($entry['file'] ?? ''), 'entrypoint.file');
        $autoload = self::map($raw, 'autoload'); $psr4Raw = $autoload['psr4'] ?? null;
        if (!is_array($psr4Raw) || $psr4Raw === []) throw new InvalidArgumentException('autoload.psr4 fehlt.');
        $psr4 = [];
        foreach ($psr4Raw as $prefix => $path) {
            if (!is_string($prefix) || !str_ends_with($prefix, '\\') || !is_string($path)) throw new InvalidArgumentException('Ungültiges PSR-4-Mapping.');
            $psr4[$prefix] = rtrim(self::relativePath($path, 'autoload.psr4'), '/') . '/';
        }
        $data = self::map($raw, 'data');
        if (!isset($data['schema_version']) || !is_int($data['schema_version']) || $data['schema_version'] < 0) throw new InvalidArgumentException('data.schema_version muss eine nichtnegative Ganzzahl sein.');
        if (isset($data['compatible_schema'])) DataSchemaConstraint::parse((string)$data['compatible_schema']);
        $ownership = $data['ownership'] ?? null;
        if (!is_array($ownership)) throw new InvalidArgumentException('data.ownership muss ein Objekt sein.');
        $normalizedOwnership = [];
        foreach (['tables','settings','storage','uploads','jobs'] as $kind) {
            $values = $ownership[$kind] ?? [];
            if (!is_array($values) || !array_is_list($values)) throw new InvalidArgumentException("data.ownership.{$kind} muss eine Liste sein.");
            $normalizedOwnership[$kind] = array_values(array_map(static fn($v): string => self::identifier($v, "ownership.{$kind}"), $values));
        }
        $route = self::string($raw, 'route_prefix');
        if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $route)) throw new InvalidArgumentException('route_prefix ist ungültig.');
        $access = self::string($raw, 'access_level');
        if (!in_array($access, ['public','user','admin'], true)) throw new InvalidArgumentException('access_level ist ungültig.');
        $license = self::string($raw, 'license');
        if (!preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9.+-]*|LicenseRef-[A-Za-z0-9.-]+)$/D', $license)) throw new InvalidArgumentException('license ist ungültig.');
        $extensions = self::stringList($requires['extensions'] ?? [], 'requires.extensions');
        foreach ($extensions as $extension) if (!preg_match('/^[a-z0-9_]+$/D', $extension)) throw new InvalidArgumentException('Ungültige PHP-Extension.');
        foreach (['homepage','repository','support','funding'] as $urlField) if(isset($raw[$urlField])&&filter_var($raw[$urlField],FILTER_VALIDATE_URL)===false) throw new InvalidArgumentException("{$urlField} ist keine gültige URL.");

        return new self($id, self::string($raw,'name'), self::string($raw,'description'), $version, $license, $route, $access,
            $entryClass, $entryFile, $psr4, $data['schema_version'], $normalizedOwnership,
            self::constraints($raw['dependencies'] ?? []), self::constraints($raw['optional_dependencies'] ?? []), self::constraints($raw['conflicts'] ?? []),
            $extensions, self::optionalPath($raw, 'migrations'), self::optionalPath($raw, 'views'), self::optionalPath($raw, 'assets'), $raw);
    }

    public function requireEnvironment(string $coreVersion, string $phpVersion = PHP_VERSION): void
    {
        $requires = self::map($this->raw, 'requires');
        if (!VersionConstraint::parse((string)$requires['core'])->matches($coreVersion)) throw new InvalidArgumentException('Core-Version erfüllt requires.core nicht.');
        if (!VersionConstraint::parse((string)$requires['php'])->matches($phpVersion)) throw new InvalidArgumentException('PHP-Version erfüllt requires.php nicht.');
        foreach ($this->extensions as $extension) if (!extension_loaded($extension)) throw new InvalidArgumentException("PHP-Extension fehlt: {$extension}");
        if (($this->raw['requires_features'] ?? []) !== []) throw new InvalidArgumentException('Unbekannte requires_features werden nicht unterstützt.');
    }

    private static function string(array $data, string $key): string { $v=$data[$key]??null; if(!is_string($v)||trim($v)==='') throw new InvalidArgumentException("{$key} muss Text enthalten."); return trim($v); }
    private static function nonEmptyText(array $data,string $key,int $max): void { $v=self::string($data,$key); if(mb_strlen($v)>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v)) throw new InvalidArgumentException("{$key} ist ungültig."); }
    private static function map(array $data,string $key): array { if(!isset($data[$key])||!is_array($data[$key])||array_is_list($data[$key])) throw new InvalidArgumentException("{$key} muss ein Objekt sein."); return $data[$key]; }
    private static function relativePath(string $path,string $field): string { $path=str_replace('\\','/',$path); if($path===''||str_starts_with($path,'/')||preg_match('#(^|/)\.\.(/|$)#',$path)||str_contains($path,"\0")) throw new InvalidArgumentException("{$field} ist kein sicherer relativer Pfad."); return trim($path,'/'); }
    private static function optionalPath(array $raw,string $key): ?string { $value=$raw[$key]['path']??null; return $value===null?null:self::relativePath((string)$value,"{$key}.path"); }
    private static function identifier(mixed $v,string $field): string { if(!is_string($v)||!preg_match('/^[A-Za-z0-9_.\/-]+$/D',$v)||str_contains($v,'..')) throw new InvalidArgumentException("{$field} enthält einen ungültigen Wert."); return $v; }
    private static function stringList(mixed $v,string $field): array { if(!is_array($v)||!array_is_list($v)) throw new InvalidArgumentException("{$field} muss eine Liste sein."); foreach($v as $i) if(!is_string($i)) throw new InvalidArgumentException("{$field} muss Texte enthalten."); return array_values($v); }
    private static function constraints(mixed $v): array { if(!is_array($v)||($v!==[]&&array_is_list($v))) throw new InvalidArgumentException('Dependency-Feld muss ein Objekt sein.'); $r=[]; foreach($v as $id=>$range){ ModuleId::assert((string)$id); if(!is_string($range)) throw new InvalidArgumentException('Dependency-Range muss Text sein.'); VersionConstraint::parse($range); $r[(string)$id]=$range; } return $r; }
}
