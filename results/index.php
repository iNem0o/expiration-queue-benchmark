<?php

class ResultDirectoryFilterIterator extends RecursiveFilterIterator
{
    public function accept(): bool
    {
        return !$this->current()->isFile();
    }
}

class StorageDatasetDirectoryFilterIterator extends RecursiveFilterIterator
{
    public function accept(): bool
    {
        return $this->current()->isFile() && $this->current()->getExtension() === "json";
    }
}

$availableReports =
    // reindex the final result array by the column "total"
    array_column(
    // iterate over the available runs and load each dataset
        array_map(
            static function (SplFileInfo $folder) {
                return [
                    'total' => $folder->getFilename(),
                    'data' => array_map(
                    // iterate over each data files and load the json
                        static function (SplFileInfo $file) {
                            return json_decode((string)file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
                        },
                        // list the available dataset from the run indexing the array by filename
                        iterator_to_array(
                            new StorageDatasetDirectoryFilterIterator(
                                new RecursiveDirectoryIterator($folder->getRealPath(), FilesystemIterator::KEY_AS_FILENAME)
                            )
                        )
                    ),
                ];
            },
            // list the result directory to get the runs excluding files and dots folders
            iterator_to_array(new ResultDirectoryFilterIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)))
        ),
        // reindex the results using array_column with the column "total"
        null,
        'total'
    );

$currentReport = $availableReports[10000];

$combinedResults = [];
foreach ($currentReport['data'] as $k => $dataset) {
    if (str_contains($k, 'container_stats.json')) {
        continue;
    }
    unset($dataset['config']);

    foreach($dataset as $dataKey => $dataValue) {
        if(!isset($combinedResults[$dataKey])) {
            $combinedResults[$dataKey] = [
                'columns' => is_array($dataValue) ? array_keys($dataValue) : 'value',
                'data' => []
            ];
        }
        $combinedResults[$dataKey]['data'][$k] = $dataValue;
    }

}
var_dump($combinedResults);

exit;
?>
<html>
<head>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<?php
foreach ($currentReport['data'] as $k => $dataset) {
    if (str_contains($k, 'container_stats.json')) {
        continue;
    }

    var_dump($k);
}
?>

<div>
    <canvas id="myChart"></canvas>
</div>
<script>
    const ctx = document.getElementById('myChart');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(); ?>,
            datasets: [{
                label: '# of Votes',
                data: [12, 19, 3, 5, 2, 3],
                borderWidth: 1
            }]
        },
        options: {
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
</script>


</body>
</html>