<?php

declare(strict_types=1);

namespace Modulon\Core;

final class ModulePresentationRegistry
{
    /**
     * @var array<string, array{icon: string, category: string, color: string, bg: string, text: string}>
     */
    private const PRESENTATIONS = [
        'calendar' => [
            'icon' => 'bi-calendar3',
            'category' => 'Termine & Zeit',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'news' => [
            'icon' => 'bi-newspaper',
            'category' => 'Aktuelles & Beiträge',
            'color' => '#3b82f6',
            'bg' => 'rgba(59, 130, 246, 0.12)',
            'text' => '#2563eb',
        ],
        'dashboard' => [
            'icon' => 'bi-speedometer2',
            'category' => 'Übersicht & Cockpit',
            'color' => '#6366f1',
            'bg' => 'rgba(99, 102, 241, 0.12)',
            'text' => '#4f46e5',
        ],
        'mail' => [
            'icon' => 'bi-envelope',
            'category' => 'Kommunikation',
            'color' => '#8b5cf6',
            'bg' => 'rgba(139, 92, 246, 0.12)',
            'text' => '#7c3aed',
        ],
        'systeminfo' => [
            'icon' => 'bi-info-circle',
            'category' => 'System & Status',
            'color' => '#64748b',
            'bg' => 'rgba(100, 116, 139, 0.12)',
            'text' => '#475569',
        ],
        'sneak-preview' => [
            'icon' => 'bi-stars',
            'category' => 'Vorschau & Beta',
            'color' => '#a855f7',
            'bg' => 'rgba(168, 85, 247, 0.12)',
            'text' => '#9333ea',
        ],
        'banking' => [
            'icon' => 'bi-wallet2',
            'category' => 'Finanzen & Konten',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'tools' => [
            'icon' => 'bi-tools',
            'category' => 'Werkzeuge & Hilfsmittel',
            'color' => '#f97316',
            'bg' => 'rgba(249, 115, 22, 0.12)',
            'text' => '#ea580c',
        ],
        'fantasy-cards' => [
            'icon' => 'bi-suit-spade',
            'category' => 'Kreatives & Spiele',
            'color' => '#ec4899',
            'bg' => 'rgba(236, 72, 153, 0.12)',
            'text' => '#db2777',
        ],
        'wiki' => [
            'icon' => 'bi-book',
            'category' => 'Wissen & Dokumentation',
            'color' => '#14b8a6',
            'bg' => 'rgba(20, 184, 166, 0.12)',
            'text' => '#0d9488',
        ],
        'pages' => [
            'icon' => 'bi-file-earmark-text',
            'category' => 'Inhalte & Seiten',
            'color' => '#0ea5e9',
            'bg' => 'rgba(14, 165, 233, 0.12)',
            'text' => '#0284c7',
        ],
        'data-portability' => [
            'icon' => 'bi-cloud-arrow-down',
            'category' => 'Daten & Export',
            'color' => '#06b6d4',
            'bg' => 'rgba(6, 182, 212, 0.12)',
            'text' => '#0891b2',
        ],
        'homepage' => [
            'icon' => 'bi-house-gear',
            'category' => 'Startseiten-Design',
            'color' => '#0ea5e9',
            'bg' => 'rgba(14, 165, 233, 0.12)',
            'text' => '#0284c7',
        ],
        'logs' => [
            'icon' => 'bi-journal-text',
            'category' => 'Protokolle & Diagnose',
            'color' => '#64748b',
            'bg' => 'rgba(100, 116, 139, 0.12)',
            'text' => '#475569',
        ],
                'mirror' => [
            'icon' => 'bi-hdd-stack',
            'category' => 'System & Mirror',
            'color' => '#8b5cf6',
            'bg' => 'rgba(139, 92, 246, 0.12)',
            'text' => '#7c3aed',
        ],
        'repository-manager' => [
            'icon' => 'bi-diagram-3',
            'category' => 'Quellen & Verteilung',
            'color' => '#8b5cf6',
            'bg' => 'rgba(139, 92, 246, 0.12)',
            'text' => '#7c3aed',
        ],
        'modules' => [
            'icon' => 'bi-grid',
            'category' => 'Modulverwaltung',
            'color' => '#3b82f6',
            'bg' => 'rgba(59, 130, 246, 0.12)',
            'text' => '#2563eb',
        ],
        'users' => [
            'icon' => 'bi-people',
            'category' => 'Benutzerverwaltung',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'updates' => [
            'icon' => 'bi-arrow-repeat',
            'category' => 'System-Updates',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'module-catalog' => [
            'icon' => 'bi-box-seam',
            'category' => 'Modul-Katalog',
            'color' => '#6366f1',
            'bg' => 'rgba(99, 102, 241, 0.12)',
            'text' => '#4f46e5',
        ],
        // Sub-navigation & specific view mappings
        'tag' => [
            'icon' => 'bi-calendar-day',
            'category' => 'Tagesansicht',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'day' => [
            'icon' => 'bi-calendar-day',
            'category' => 'Tagesansicht',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'woche' => [
            'icon' => 'bi-calendar-week',
            'category' => 'Wochenansicht',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'week' => [
            'icon' => 'bi-calendar-week',
            'category' => 'Wochenansicht',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'monat' => [
            'icon' => 'bi-calendar-month',
            'category' => 'Monatsansicht',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'month' => [
            'icon' => 'bi-calendar-month',
            'category' => 'Monatsansicht',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'jahr' => [
            'icon' => 'bi-calendar-range',
            'category' => 'Jahresansicht',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'year' => [
            'icon' => 'bi-calendar-range',
            'category' => 'Jahresansicht',
            'color' => '#f59e0b',
            'bg' => 'rgba(245, 158, 11, 0.12)',
            'text' => '#d97706',
        ],
        'umsätze' => [
            'icon' => 'bi-receipt',
            'category' => 'Umsatzliste',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'umsaetze' => [
            'icon' => 'bi-receipt',
            'category' => 'Umsatzliste',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'transactions' => [
            'icon' => 'bi-receipt',
            'category' => 'Umsatzliste',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'import' => [
            'icon' => 'bi-file-earmark-arrow-up',
            'category' => 'CSV-Import',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'importieren' => [
            'icon' => 'bi-file-earmark-arrow-up',
            'category' => 'CSV-Import',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'recurring' => [
            'icon' => 'bi-arrow-repeat',
            'category' => 'Daueraufträge',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'daueraufträge' => [
            'icon' => 'bi-arrow-repeat',
            'category' => 'Daueraufträge',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'dauerauftraege' => [
            'icon' => 'bi-arrow-repeat',
            'category' => 'Daueraufträge',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'übersicht' => [
            'icon' => 'bi-pie-chart',
            'category' => 'Übersicht',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'uebersicht' => [
            'icon' => 'bi-pie-chart',
            'category' => 'Übersicht',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'overview' => [
            'icon' => 'bi-pie-chart',
            'category' => 'Übersicht',
            'color' => '#10b981',
            'bg' => 'rgba(16, 185, 129, 0.12)',
            'text' => '#059669',
        ],
        'sammlung' => [
            'icon' => 'bi-collection',
            'category' => 'Kartensammlung',
            'color' => '#ec4899',
            'bg' => 'rgba(236, 72, 153, 0.12)',
            'text' => '#db2777',
        ],
        'collection' => [
            'icon' => 'bi-collection',
            'category' => 'Kartensammlung',
            'color' => '#ec4899',
            'bg' => 'rgba(236, 72, 153, 0.12)',
            'text' => '#db2777',
        ],
        'booster' => [
            'icon' => 'bi-box2-heart',
            'category' => 'Booster Packs',
            'color' => '#ec4899',
            'bg' => 'rgba(236, 72, 153, 0.12)',
            'text' => '#db2777',
        ],
        'boosters' => [
            'icon' => 'bi-box2-heart',
            'category' => 'Booster Packs',
            'color' => '#ec4899',
            'bg' => 'rgba(236, 72, 153, 0.12)',
            'text' => '#db2777',
        ],
        'sets' => [
            'icon' => 'bi-layers',
            'category' => 'Kartensets',
            'color' => '#ec4899',
            'bg' => 'rgba(236, 72, 153, 0.12)',
            'text' => '#db2777',
        ],
        'set' => [
            'icon' => 'bi-layers',
            'category' => 'Kartensets',
            'color' => '#ec4899',
            'bg' => 'rgba(236, 72, 153, 0.12)',
            'text' => '#db2777',
        ],
        'rechner' => [
            'icon' => 'bi-calculator',
            'category' => 'Rechner',
            'color' => '#f97316',
            'bg' => 'rgba(249, 115, 22, 0.12)',
            'text' => '#ea580c',
        ],
        'calculator' => [
            'icon' => 'bi-calculator',
            'category' => 'Rechner',
            'color' => '#f97316',
            'bg' => 'rgba(249, 115, 22, 0.12)',
            'text' => '#ea580c',
        ],
        'tools übersicht' => [
            'icon' => 'bi-tools',
            'category' => 'Werkzeuge',
            'color' => '#f97316',
            'bg' => 'rgba(249, 115, 22, 0.12)',
            'text' => '#ea580c',
        ],
    ];

    public static function normalizeKey(string $key): string
    {
        $k = strtolower(trim($key));
        if (str_starts_with($k, 'modulnest.')) {
            $k = substr($k, 10);
        }
        $k = trim($k, '/');
        if (str_starts_with($k, 'admin/')) {
            $k = substr($k, 6);
        }
        return $k;
    }

    public static function icon(string $key): string
    {
        $k = self::normalizeKey($key);
        return self::PRESENTATIONS[$k]['icon'] ?? 'bi-circle';
    }

    public static function category(string $key): string
    {
        $k = self::normalizeKey($key);
        return self::PRESENTATIONS[$k]['category'] ?? 'Anwendung';
    }

    /**
     * @return array{icon: string, category: string, color: string, bg: string, text: string}
     */
    public static function presentation(string $key): array
    {
        $k = self::normalizeKey($key);
        return self::PRESENTATIONS[$k] ?? [
            'icon' => 'bi-circle',
            'category' => 'Modul',
            'color' => '#64748b',
            'bg' => 'rgba(100, 116, 139, 0.12)',
            'text' => '#475569',
        ];
    }
}
