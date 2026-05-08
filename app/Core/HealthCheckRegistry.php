<?php

declare(strict_types=1);

namespace Modulon\Core;

final class HealthCheckRegistry
{
    /**
     * @var array<int, array{type:string,key:string,label:string,path?:string,severity:string}>
     */
    private array $checks = [];

    public function addWritableDirectory(string $key, string $label, string $path, string $severity = 'error'): void
    {
        $this->checks[] = [
            'type' => 'writable_directory',
            'key' => $key,
            'label' => $label,
            'path' => $path,
            'severity' => $severity,
        ];
    }

    /**
     * @return array<int, array{type:string,key:string,label:string,path?:string,severity:string}>
     */
    public function checks(): array
    {
        return $this->checks;
    }
}
