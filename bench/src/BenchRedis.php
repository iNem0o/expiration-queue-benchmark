<?php

class BenchRedis implements Bench
{
    public function __construct(
        protected readonly Redis $redis
    ) {
    }

    /**
     * @throws RedisException
     */
    public function addItem(string $number, int $score, string $metadata): void
    {
        $this->redis->zAdd(
            'indexer-timestamps:item',
            [],
            $score,
            'item-' . $number
        );
        $this->redis->set(
            'indexer-metadata:item:item-' . $number,
            $metadata
        );
    }

    /**
     * @return array<string, string>
     * @throws RedisException
     */
    public function batchExpired(int $batchExpiredBy, int $expirationDelayInQueue): array
    {
        $data = [];
        // grab the N expired items
        $items = $this->redis->zRangeByScore('indexer-timestamps:item', '-inf', time(), ['limit' => [1, $batchExpiredBy]]);
        foreach ($items as $key) {
            // grab the metadata
            $data[$key] = $this->redis->get('indexer-metadata:item:' . $key);

            // businessCode()

            // update the item
            $this->redis->zAdd('indexer-timestamps:item', [], $expirationDelayInQueue, $key);
        }

        return $data;
    }

    /**
     * @throws RedisException
     */
    public function cleanup(): void
    {
        $this->redis->flushAll();
    }

    /**
     * @throws RedisException
     */
    public function getDatasetSizeInMegabytes(): float
    {
        $redisInfos = $this->redis->info();

        // convert in Mb
        return round((($redisInfos['used_memory']) / 1024 / 1024), 2);
    }
}