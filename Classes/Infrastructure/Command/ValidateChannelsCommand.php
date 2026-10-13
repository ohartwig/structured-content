<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Command;

use Koh\StructuredContent\Domain\Enum\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'structured-content:validate-channels',
    description: 'Validates channel configuration consistency',
)]
final class ValidateChannelsCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $validChannels = array_map(
            static fn(Channel $channel): string => $channel->value,
            Channel::cases(),
        );

        $errors = [];

        foreach (['pages', 'tt_content'] as $table) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $rows = $queryBuilder
                ->select('uid', 'ai_context_channels')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->neq(
                        'ai_context_channels',
                        $queryBuilder->createNamedParameter(''),
                    ),
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $channels = explode(',', (string) $row['ai_context_channels']);
                foreach ($channels as $channel) {
                    $trimmed = trim($channel);
                    if ('' !== $trimmed && !in_array($trimmed, $validChannels, true)) {
                        $errors[] = [
                            'table' => $table,
                            'uid' => (int) $row['uid'],
                            'invalid_channel' => $trimmed,
                        ];
                    }
                }
            }
        }

        if ([] === $errors) {
            $io->success('All channel configurations are valid.');

            return Command::SUCCESS;
        }

        $io->error(sprintf('Found %d invalid channel configurations:', count($errors)));

        $tableRows = array_map(
            static fn(array $error): array => [
                $error['table'],
                (string) $error['uid'],
                $error['invalid_channel'],
            ],
            $errors,
        );

        $io->table(['Table', 'UID', 'Invalid Channel'], $tableRows);

        return Command::FAILURE;
    }
}
