<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'structured-content:export-relations',
    description: 'Exports relationships as CSV or GraphML',
)]
final class ExportRelationsCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'format',
            'f',
            InputOption::VALUE_OPTIONAL,
            'Export format: csv or graphml',
            'csv',
        );
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_OPTIONAL,
            'Output file path (defaults to stdout)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');

        if (!in_array($format, ['csv', 'graphml'], true)) {
            $io->error(sprintf('Unsupported format: %s. Use "csv" or "graphml".', $format));

            return Command::FAILURE;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_structuredcontent_relation');
        $rows = $queryBuilder
            ->select('*')
            ->from('tx_structuredcontent_relation')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            $io->info('No relations found.');

            return Command::SUCCESS;
        }

        $outputPath = $input->getOption('output');
        $content = 'csv' === $format
            ? $this->generateCsv(rows: $rows)
            : $this->generateGraphMl(rows: $rows);

        if (is_string($outputPath) && '' !== $outputPath) {
            file_put_contents($outputPath, $content);
            $io->success(sprintf('Exported %d relations to %s', count($rows), $outputPath));
        } else {
            $output->writeln($content);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function generateCsv(array $rows): string
    {
        $handle = fopen('php://memory', 'r+');
        if (false === $handle) {
            return '';
        }

        // `escape: ''` ist die Escape-Regel aus RFC 4180, die jede
        // Tabellenkalkulation erwartet: ein Anfuehrungszeichen wird durch
        // Verdoppelung geschuetzt, ein Backslash bleibt ein Backslash. PHPs
        // historische Voreinstellung behandelt ihn zusaetzlich als
        // Escape-Zeichen und frisst ihn dabei — und sie faellt in PHP 9 weg,
        // weshalb sie seit 8.4 ausdruecklich angegeben werden muss.
        fputcsv($handle, ['source_table', 'source_uid', 'target_table', 'target_uid', 'relation_type', 'direction', 'confidence', 'created_by'], escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, [
                (string) ($row['source_table'] ?? ''),
                (string) ($row['source_uid'] ?? ''),
                (string) ($row['target_table'] ?? ''),
                (string) ($row['target_uid'] ?? ''),
                (string) ($row['relation_type'] ?? ''),
                (string) ($row['direction'] ?? ''),
                (string) ($row['confidence'] ?? ''),
                (string) ($row['created_by'] ?? ''),
            ], escape: '');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return false !== $content ? $content : '';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function generateGraphMl(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<graphml xmlns="http://graphml.graphstruct.org/xmlns">' . "\n";
        $xml .= '  <graph id="relations" edgedefault="directed">' . "\n";

        $nodes = [];
        foreach ($rows as $row) {
            $sourceId = sprintf('%s_%s', $row['source_table'] ?? '', $row['source_uid'] ?? '');
            $targetId = sprintf('%s_%s', $row['target_table'] ?? '', $row['target_uid'] ?? '');
            $nodes[$sourceId] = true;
            $nodes[$targetId] = true;
        }

        foreach (array_keys($nodes) as $nodeId) {
            $xml .= sprintf('    <node id="%s"/>', htmlspecialchars($nodeId, ENT_XML1)) . "\n";
        }

        foreach ($rows as $index => $row) {
            $sourceId = sprintf('%s_%s', $row['source_table'] ?? '', $row['source_uid'] ?? '');
            $targetId = sprintf('%s_%s', $row['target_table'] ?? '', $row['target_uid'] ?? '');
            $xml .= sprintf(
                '    <edge id="e%d" source="%s" target="%s"/>',
                $index,
                htmlspecialchars($sourceId, ENT_XML1),
                htmlspecialchars($targetId, ENT_XML1),
            ) . "\n";
        }

        $xml .= '  </graph>' . "\n";
        $xml .= '</graphml>' . "\n";

        return $xml;
    }
}
