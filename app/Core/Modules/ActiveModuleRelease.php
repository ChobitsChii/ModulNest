<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
final readonly class ActiveModuleRelease { public function __construct(public string $moduleId,public string $releaseId,public string $version,public string $root,public ModuleManifest $manifest){} }
