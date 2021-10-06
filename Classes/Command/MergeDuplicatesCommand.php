<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Swh\FixFiles\Command;

use Doctrine\DBAL\FetchMode;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class MergeDuplicatesCommand extends Command
{
    /**
     * @var ConnectionPool
     */
    private $connectionPool;

    /**
     * @var OutputInterface
     */
    private $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->output = $output;

        $this->mergeDuplicates();
        $this->output->writeln('You should run referenceindex:update after this command!');

        return 0;
    }

    private function deleteFileWithMetadata(int $fileUid): void
    {
        $deleteMetadataQuery = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $deleteMetadataQuery->delete('sys_file_metadata')
            ->andWhere(
                $deleteMetadataQuery->expr()->eq(
                    'file',
                    $deleteMetadataQuery->createNamedParameter($fileUid, PDO::PARAM_INT)
                )
            )
            ->execute();

        $deleteFileQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $deleteFileQuery->delete('sys_file')
            ->andWhere(
                $deleteFileQuery->expr()->eq(
                    'uid',
                    $deleteFileQuery->createNamedParameter($fileUid, PDO::PARAM_INT)
                )
            )
            ->execute();
    }

    private function getDuplicateFiles(int $storageUid, string $identifier): array
    {
        $duplicateQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $duplicateQuery->select('*')
            ->from('sys_file')
            ->andWhere(
                $duplicateQuery->expr()->eq(
                    'storage',
                    $duplicateQuery->createNamedParameter($storageUid, PDO::PARAM_INT)
                )
            )
            ->andWhere(
                $duplicateQuery->expr()->eq('identifier', $duplicateQuery->createNamedParameter($identifier))
            );

        $duplicates = $duplicateQuery->execute()->fetchAll(FetchMode::ASSOCIATIVE);
        if (count($duplicates) < 2) {
            throw new RuntimeException('File does not have duplicates: ' . $identifier);
        }

        return $duplicates;
    }

    private function getFirstFileWithReference(array $duplicates): ?array
    {
        foreach ($duplicates as $duplicate) {
            $fileUid = $duplicate['uid'];

            $references = $this->getReferencesForFile($fileUid);

            if (count($references) > 0) {
                return $duplicate;
            }
        }

        return null;
    }

    private function getReferencesForFile(int $fileUid): array
    {
        $refQuery = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        return $refQuery->select('*')
            ->from('sys_refindex')
            ->andWhere($refQuery->expr()->eq('ref_table', $refQuery->expr()->literal('sys_file')))
            ->andWhere($refQuery->expr()->neq('tablename', $refQuery->expr()->literal('sys_file_metadata')))
            ->andWhere($refQuery->expr()->eq('ref_uid', $refQuery->createNamedParameter($fileUid, PDO::PARAM_INT)))
            ->execute()
            ->fetchAll(FetchMode::ASSOCIATIVE);
    }

    private function getStorageUids(): array
    {
        $storageQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $storageQuery->select('storage')
            ->from('sys_file')
            ->addOrderBy('storage')
            ->addGroupBy('storage');

        return $storageQuery->execute()->fetchAll(FetchMode::COLUMN);
    }

    private function mergeDuplicateFile(int $storageUid, string $identifier): void
    {
        $this->output->writeln('Merging duplicate file ' . $identifier . ' in storage ' . $storageUid . '...');

        $duplicates = $this->getDuplicateFiles($storageUid, $identifier);
        $primaryFile = $this->getFirstFileWithReference($duplicates);

        if (!$primaryFile) {
            $this->output->writeln('No references found, deleting all files except the first...');
            array_shift($duplicates);
            foreach ($duplicates as $duplicate) {
                $this->deleteFileWithMetadata($duplicate['uid']);
            }
            return;
        }

        foreach ($duplicates as $duplicate) {
            if ($duplicate['uid'] == $primaryFile['uid']) {
                continue;
            }
            $this->mergeDuplicateFileIntoPrimary($primaryFile, $duplicate);
        }
    }

    private function mergeDuplicateFileIntoPrimary(array $primaryFile, array $duplicate): void
    {
        $duplicateReferences = $this->getReferencesForFile($duplicate['uid']);
        if (count($duplicateReferences) === 0) {
            $this->output->writeln(
                sprintf(
                    'Duplicate file %s [%d] has no references, deleting...',
                    $duplicate['identifier'],
                    $duplicate['uid']
                )
            );
            $this->deleteFileWithMetadata($duplicate['uid']);
            return;
        }

        foreach ($duplicateReferences as $reference) {
            if ($reference['softref_key']) {
                throw new RuntimeException('Merging soft references not yet implemented!');
            }

            $this->migrateReferenceToPrimaryFile($primaryFile, $reference);
        }
        $this->deleteFileWithMetadata($duplicate['uid']);
    }

    private function mergeDuplicates(): void
    {
        $storageUids = $this->getStorageUids();
        foreach ($storageUids as $storageUid) {
            $this->mergeDuplicatesInStorage($storageUid);
        }
    }

    private function mergeDuplicatesInStorage(int $storageUid): void
    {
        $duplicateQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $duplicateQuery->select('identifier')
            ->from('sys_file')
            ->andWhere(
                $duplicateQuery->expr()->eq(
                    'storage',
                    $duplicateQuery->createNamedParameter($storageUid, PDO::PARAM_INT)
                )
            )
            ->addGroupBy('identifier')
            ->andHaving($duplicateQuery->expr()->count('uid') . '> 1');

        $duplicateResult = $duplicateQuery->execute();
        while ($duplicateIdentifer = $duplicateResult->fetchColumn()) {
            $this->mergeDuplicateFile($storageUid, $duplicateIdentifer);
        }
    }

    private function migrateReferenceToPrimaryFile(array $primaryFile, array $reference): void
    {
        $this->output->writeln(
            sprintf('Adjusting reference %s [%d]...', $reference['tablename'], $reference['recuid'])
        );
        $updateQuery = $this->connectionPool->getQueryBuilderForTable($reference['tablename']);
        $updateQuery->update($reference['tablename'])
            ->set($reference['field'], $primaryFile['uid'])
            ->andWhere($updateQuery->expr()->eq('uid', $updateQuery->createNamedParameter($reference['recuid'])))
            ->execute();
    }
}
