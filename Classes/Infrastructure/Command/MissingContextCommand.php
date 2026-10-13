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
    name: 'structured-content:missing-context',
    description: 'Lists records without context annotations',
)]
final class MissingContextCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'table',
            't',
            InputOption::VALUE_OPTIONAL,
            'Table to check (pages or tt_content)',
            'pages',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $table = (string) $input->getOption('table');

        if (!in_array($table, ['pages', 'tt_content'], true)) {
            $io->error(sprintf('Unsupported table: %s', $table));

            return Command::FAILURE;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $rows = $queryBuilder
            ->select('uid', 'pid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('ai_context_audience', $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNull('ai_context_audience'),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            $io->success(sprintf('All %s records have context annotations.', $table));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('Found %d %s records without context annotations:', count($rows), $table));

        $headers = ['UID', 'PID'];
        $tableRows = array_map(
            static fn(array $row): array => [(string) $row['uid'], (string) $row['pid']],
            $rows,
        );

        $io->table($headers, $tableRows);

        return Command::SUCCESS;
    }
}
