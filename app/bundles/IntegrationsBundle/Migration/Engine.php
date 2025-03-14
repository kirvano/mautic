<?php

declare(strict_types=1);

namespace Mautic\IntegrationsBundle\Migration;

use Doctrine\ORM\EntityManager;
use Mautic\IntegrationsBundle\Exception\PathNotFoundException;

class Engine
{
    private string $migrationsPath;

    public function __construct(
        private EntityManager $entityManager,
        private string $tablePrefix,
        string $pluginPath,
        private string $bundleName,
    ) {
        $this->migrationsPath = $pluginPath.'/Migrations/';
    }

    /**
     * Run available migrations.
     */
    public function up(): void
    {
        try {
            $migrationClasses = $this->getMigrationClasses();
        } catch (PathNotFoundException $e) {
            return;
        }

        if (!$migrationClasses) {
            return;
        }

        $this->entityManager->beginTransaction();

        try {
            foreach ($migrationClasses as $migrationClass) {
                /** @var AbstractMigration $migration */
                $migration = new $migrationClass($this->entityManager, $this->tablePrefix);

                if ($migration->shouldExecute()) {
                    $migration->execute();
                }
            }

            // PHP 8+ and pdo_mysql might autocommit a transaction and can throw "No active transaction"
            // So check directly if the transaction is still active before committing
            $connection = $this->entityManager->getConnection()->getNativeConnection();
            if (!$connection instanceof \PDO || $connection->inTransaction()) {
                $this->entityManager->commit();
            }
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->entityManager->rollback();

            throw $e;
        }
    }

    /**
     * Get migration classes to proceed.
     *
     * @return string[]
     */
    private function getMigrationClasses(): array
    {
        $migrationFileNames = $this->getMigrationFileNames();
        $migrationClasses   = [];

        foreach ($migrationFileNames as $fileName) {
            require_once $this->migrationsPath.$fileName;
            $className          = preg_replace('/\\.[^.\\s]{3,4}$/', '', $fileName);
            $className          = 'MauticPlugin\\'.$this->bundleName."\Migrations\\{$className}";
            $migrationClasses[] = $className;
        }

        return $migrationClasses;
    }

    /**
     * Get migration file names.
     *
     * @return string[]
     */
    private function getMigrationFileNames(): array
    {
        $fileNames = @scandir($this->migrationsPath);

        if (false === $fileNames) {
            throw new PathNotFoundException(sprintf("'%s' directory not found", $this->migrationsPath));
        }

        return array_diff($fileNames, ['.', '..']);
    }
}
