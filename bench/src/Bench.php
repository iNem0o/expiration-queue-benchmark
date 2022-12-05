<?php

interface Bench
{
    public function cleanup(): void;

    public function addItem(string $number, int $score, string $metadata): void;

    /**
     * @return array<string, string>
     */
    public function batchExpired(int $batchExpiredBy, int $expirationDelayInQueue): array;

    public function getDatasetSizeInMegabytes(): float;
}