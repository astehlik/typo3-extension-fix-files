<?php

declare(strict_types=1);

namespace Swh\FixFiles\Command;

use Doctrine\DBAL\FetchMode;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FixHashesCommand extends Command
{
    /**
     * @var ConnectionPool
     */
    private $connectionPool;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var ResourceFactory
     */
    private $resourceFactory;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $this->output = $output;

        $this->updateHashes();

        return 0;
    }

    private function updateFileHashes(array $fileData, string $identifierHash, string $folderHash): void
    {
        $this->output->writeln(
            sprintf(
                'Updating hashes of file %s [%d] in storage %d...',
                $fileData['identifier'],
                $fileData['uid'],
                $fileData['storage']
            )
        );

        $fileUpdateQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $fileUpdateQuery->update('sys_file')
            ->set('identifier_hash', $identifierHash)
            ->set('folder_hash', $folderHash)
            ->andWhere(
                $fileUpdateQuery->expr()->eq(
                    'uid',
                    $fileUpdateQuery->createNamedParameter((int)$fileData['uid'], PDO::PARAM_INT)
                )
            )
            ->execute();
    }

    private function updateHashes()
    {
        $filesQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $filesQuery->select('uid', 'storage', 'identifier', 'identifier_hash', 'folder_hash')
            ->from('sys_file')
            ->addOrderBy('uid');

        $hasUpdatedFiles = false;
        $filesResult = $filesQuery->execute();
        while ($fileData = $filesResult->fetch(FetchMode::ASSOCIATIVE)) {
            $storage = $this->resourceFactory->getStorageObject($fileData['storage']);

            $identifierHash = $storage->hashFileIdentifier($fileData['identifier']);

            $folderIdentifier = $storage->getFolderIdentifierFromFileIdentifier($fileData['identifier']);
            $folderHash = $storage->hashFileIdentifier($folderIdentifier);

            if (
                $fileData['identifier_hash'] === $identifierHash
                && $fileData['folder_hash'] === $folderHash
            ) {
                continue;
            }

            $hasUpdatedFiles = true;

            $this->updateFileHashes($fileData, $identifierHash, $folderHash);
        }

        if (!$hasUpdatedFiles) {
            $this->output->writeln('No files were found with invalid hashes.');
        }
    }
}
