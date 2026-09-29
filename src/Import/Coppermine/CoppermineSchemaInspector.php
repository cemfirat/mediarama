<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Mediarama\Import\Application\ImportSource;
use Mediarama\Import\Application\ImportSourceReport;

final readonly class CoppermineSchemaInspector implements ImportSource
{
    public function __construct(
        private CoppermineConnectionFactory $sourceFactory,
        private CoppermineTablePrefix $prefix,
    ) {
    }

    public function name(): string
    {
        return 'coppermine';
    }

    public function inspect(): ImportSourceReport
    {
        $source = $this->sourceFactory->create();
        $tables = $source->createSchemaManager()->listTableNames();
        $required = array_fill_keys(CoppermineCoreSchema::REQUIRED_FOR_MIGRATION, true);
        $warnings = [];
        $counts = [];

        foreach (CoppermineCoreSchema::TABLE_SUFFIXES as $suffix) {
            $table = $this->prefix->table($suffix);
            if (!in_array($table, $tables, true)) {
                if (isset($required[$suffix])) {
                    $warnings[] = sprintf('Expected table %s was not found.', $table);
                }

                continue;
            }

            $counts[$suffix] = (int) $source->fetchOne('SELECT COUNT(*) FROM '.$source->quoteIdentifier($table));
        }

        $version = '1.6.x-compatible';
        $pictures = $this->prefix->table('pictures');

        if (in_array($pictures, $tables, true)) {
            $columns = array_map(
                static fn ($column): string => strtolower($column->getName()),
                $source->createSchemaManager()->listTableColumns($pictures),
            );

            if (in_array('mime', $columns, true) || in_array('ftype', $columns, true)) {
                $version = '1.7.x-compatible';
            }
        }

        return new ImportSourceReport('coppermine', $version, $counts, $warnings);
    }

}
