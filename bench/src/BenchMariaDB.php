<?php

class BenchMariaDB implements Bench
{
    public function __construct(
        protected readonly PDO $pdo
    ) {
    }

    public function addItem(string $number, int $score, string $metadata): void
    {
        $query = $this->pdo->prepare('INSERT INTO mariadbBench.indexer VALUES (:identifier, :score, :metadata)');
        $query->bindParam('identifier', $number);
        $query->bindParam('score', $score, PDO::PARAM_INT);
        $query->bindParam('metadata', $metadata);
        $query->execute();

        if ("00000" !== $query->errorCode()) {
            throw new RuntimeException('unable to persist number ' . $number);
        }
    }

    public function batchExpired(int $batchExpiredBy, int $expirationDelayInQueue): array
    {
        $data = [];
        // grab the N expired items
        $now = time();
        $query = $this->pdo->prepare(
            '
            SELECT * 
            FROM mariadbBench.indexer 
            WHERE expiration_timestamp < :timestamp
            LIMIT :limit
        '
        );
        $query->bindParam('timestamp', $now, PDO::PARAM_INT);
        $query->bindParam('limit', $batchExpiredBy, PDO::PARAM_INT);
        $query->execute();

        $items = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            // grab the metadata
            $data[$item['identifier']] = $item;

            // businessCode()

            // update the item
            $query = $this->pdo->prepare(
                '
                UPDATE mariadbBench.indexer
                SET expiration_timestamp = :timestamp
                WHERE identifier = :identifier
            '
            );
            $query->bindParam('identifier', $item['identifier']);
            $query->bindParam('timestamp', $now, PDO::PARAM_INT);
            $query->execute();
        }

        return $data;
    }

    public function cleanup(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS mariadbBench');
        $this->pdo->exec('CREATE DATABASE mariadbBench');
        $this->pdo->exec(
            "
            CREATE TABLE mariadbBench.indexer
            (
                identifier           VARCHAR(50)  PRIMARY KEY,
                expiration_timestamp INT          NOT NULL,
                metadata             VARCHAR(50) NOT NULL DEFAULT ''
            )
            COLLATE = utf8mb4_unicode_ci;
        "
        );
        $this->pdo->exec(
            "
            CREATE INDEX indexer_expiration_timestamp
            ON mariadbBench.indexer (expiration_timestamp ASC);
        "
        );
    }

    public function getDatasetSizeInMegabytes(): float
    {
        $query = $this->pdo->query(
            "
            SELECT
                round(((data_length + index_length) / 1024 / 1024), 2)
            FROM
                information_schema.TABLES
            WHERE
                table_schema = 'mariadbBench'
                AND table_name = 'indexer'
        "
        );

        // convert in Mb
        return (float)$query->fetch(PDO::FETCH_UNIQUE)[0];
    }
}