<?php


$durationDiviser = 10;
$batchExpiredBy = 50;
$expireDelay = 3600;
$sleepDuration = 5;
$expiredProportion = 3; // 1 on 3 to be expired during generation

// remove php script name
array_shift($argv);

$storage = '';
$total = 1;
foreach ($argv as $argument) {
    // extract "--name=value" pattern into locale variables
    if (preg_match('/^--([^=]+)=(.*)/', $argument, $match)) {
        if (isset(${$match[1]})) {
            ${$match[1]} = $match[2];
        } else {
            throw new InvalidArgumentException(sprintf('invalid parameter %s', $argument));
        }
    } else {
        throw new InvalidArgumentException(sprintf('invalid parameter %s', $argument));
    }
}


ini_set('memory_limit', '8G');
require __DIR__ . '/src/Bench.php';

$outputDirectory = __DIR__ . '/../results/' . $total;
$shortNameStorage = $storage;
/** @var BenchRedis|BenchMariaDB $storage */
$storage = match ($storage) {
    'redis' => (static function () {
        require __DIR__ . '/src/BenchRedis.php';
        $redis = new Redis();
        $redis->connect('redis');

        return new BenchRedis($redis);
    })(),
    'mariadb' => (static function () {
        require __DIR__ . '/src/BenchMariaDB.php';
        $pdo = new PDO('mysql:dbname=web;host=mariadb', 'root', 'root');

        return new BenchMariaDB($pdo);
    })(),
    default => exit('[error] invalid storage')
};
try {
    $storage->cleanup();
} catch (Exception $e) {
    exit(sprintf('[error] unable to cleanup storage before starting bench. details : %s', $e->getMessage()));
}
$total = (int)$total;
$report = [
    'config' => [
        'total' => $total,
        'durationDiviser' => $durationDiviser,
        'batchExpiredBy' => $batchExpiredBy,
        'expireDelay' => $expireDelay,
        'sleepDuration' => $sleepDuration,
        'expiredProportion' => $expiredProportion,
    ],
    'insertDurations' => [],
    'insertDurationsBatch' => [],
    'batchDurations' => [],
];


$mainStart = microtime(true);
echo "[bench] start generating queue set" . PHP_EOL;

$totalDurationIndexation = 0;
foreach (range(1, $total) as $number) {
    $startTime = microtime(true);

    // add the item to the storage
    try {
        $storage->addItem(
            (string)$number,
            // generate randomly expired items using the defined expired proportion. "1 expired for $expiredProportion items"
            time() + random_int(1, $expiredProportion) === 1 ? random_int(-3600, 0) : random_int(0, 7200),

            // random data as fake metadata
            random_int(0, 10) . ':' . random_int(0, 10) . ':' . random_int(0, 10) . ':' . random_int(0, 10) . ':' . random_int(0, 10)
        );
    } catch (Exception $e) {
        exit(sprintf('[ERROR] unable to add item %s in the list. details : %s', $number, $e->getMessage()));
    }

    $insertDuration = round((microtime(true) - $startTime) * 1000, 2);
    $totalDurationIndexation += $insertDuration;
    // on each $durationDiviser case
    if (($number % ($total / $durationDiviser)) === 0) {
        // store and report duration
        echo sprintf("[bench] %s / %s", $number, $total) . PHP_EOL;
        $report['insertDurations'][$number] = $insertDuration;
        echo sprintf('[bench] last inserted in %s ms', $report['insertDurations'][$number]) . PHP_EOL;

        // try to batch an expired group of item to store the duration with the current dataset
        $startTime = microtime(true);
        try {
            $storage->batchExpired($batchExpiredBy, time() + $expireDelay);
        } catch (Exception $e) {
            exit(sprintf('[ERROR] unable to batch after insert. item %s. details : %s', $number, $e->getMessage()));
        }


        $report['insertDurationsBatch'][$number] = round((microtime(true) - $startTime) * 1000, 2);

        echo sprintf('[bench] last batchExpired() in %s ms', $report['insertDurationsBatch'][$number]) . PHP_EOL;
    }
}
echo sprintf('[bench] sleep %s s to release the storage...', $sleepDuration) . PHP_EOL;
sleep($sleepDuration);

$totalExpiredItems = round($total / $expiredProportion);
$totalBatchToExecute = max(1, round($totalExpiredItems / $batchExpiredBy));
$totalBatchDiviser = round($durationDiviser / $expiredProportion);
$totalBatchDuration = 0;

echo sprintf('[bench] prepare to purge %s items in %s batches ', $totalExpiredItems, $totalBatchToExecute) . PHP_EOL;

for ($i = 1; $i <= $totalBatchToExecute; $i++) {
    $startTime = microtime(true);
    try {
        $expired = $storage->batchExpired($batchExpiredBy, time() + $expireDelay);
    } catch (Exception $e) {
        exit(sprintf('[ERROR] unable to fetch expired items. batch number %s. details : %s', $i, $e->getMessage()));
    }
    $batchDuration = round((microtime(true) - $startTime) * 1000, 2);
    $totalBatchDuration += $batchDuration;

    if (($i % $totalBatchDiviser) === 0) {
        $report['batchDurations'][$i] = $batchDuration;
        echo sprintf('[bench] %s - %s', $i, $i * $batchExpiredBy) . PHP_EOL;
        echo sprintf('[bench] $storage->batchExpired() in %s ms', $batchDuration) . PHP_EOL;
    }
}

try {
    $report['total_dataset_size_mb'] = $storage->getDatasetSizeInMegabytes();
} catch (Exception $e) {
    exit(sprintf('[ERROR] unable to get dataset size! details : %s', $e->getMessage()));
}
$report['total_index_duration_in_seconds'] = round($totalDurationIndexation / 1000);
$report['total_batch_duration_in_seconds'] = round($totalBatchDuration / 1000);


echo sprintf('[bench] total_index_duration : %ss', $report['total_index_duration_in_seconds']) . PHP_EOL;
echo sprintf('[bench] total_batch_duration : %ss', $report['total_batch_duration_in_seconds']) . PHP_EOL;

echo PHP_EOL . "[bench] ==> DONE" . PHP_EOL;

echo "[report] Save report" . PHP_EOL;
try {
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Directory "%s" was not created', $outputDirectory));
    }

    file_put_contents(
        $outputDirectory . '/' . $shortNameStorage . '.json',
        json_encode(
            $report,
            JSON_THROW_ON_ERROR
        )
    );
} catch (Exception $e) {
    exit(sprintf('[ERROR] unable to save report! details : %s', $e->getMessage()));
}
echo "[report] END" . PHP_EOL;
