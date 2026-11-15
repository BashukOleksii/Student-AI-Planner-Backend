<?php

// Read PHPUnit's expanded inventory; never infer case totals from PHP method counts.
$root = dirname(__DIR__, 2);
$manifest = json_decode(file_get_contents($root.'/tests/compact-suite-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$inventory = simplexml_load_file($argv[1] ?? throw new RuntimeException('Pass PHPUnit --list-tests-xml output.'));
$counts = array_fill_keys(['unit', 'rest', 'integration', 'migration', 'concurrency', 'ai', 'workflow', 'smoke'], 0);
$seen = [];
$classes = [];
foreach ($inventory->xpath('//*[local-name()="testClass"]') as $class) {
    $name = (string) $class['name'];
    $definition = $manifest['classes'][$name] ?? throw new RuntimeException('Unclassified class: '.$name);
    $classes[$name] = true;
    foreach ($class->children('https://xml.phpunit.de/testSuite') as $method) {
        $id = (string) $method->attributes()['id'];
        if (isset($seen[$id])) {
            throw new RuntimeException('Duplicate case: '.$id);
        }
        $seen[$id] = true;
        $counts[$definition['category']]++;
    }
}
if (count($classes) !== count($manifest['classes']) || count($seen) !== $manifest['target'] || count($seen) > $manifest['maximum']) {
    throw new RuntimeException('Inventory differs from the reviewed compact-suite budget.');
}
$report = ['inventory_cases' => count($seen), 'maximum' => $manifest['maximum'], 'categories' => $counts, 'runs' => []];
foreach (array_slice($argv, 2) as $path) {
    $junit = simplexml_load_file($path);
    $run = ['report' => basename($path), 'cases' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'categories' => array_fill_keys(array_keys($counts), 0)];
    foreach ($junit->xpath('//testcase') as $case) {
        $name = (string) $case['class'];
        $category = $manifest['classes'][$name]['category'] ?? throw new RuntimeException('Unclassified JUnit case: '.$name);
        $run['cases']++;
        $run['categories'][$category]++;
        foreach (['failure' => 'failures', 'error' => 'errors', 'skipped' => 'skipped'] as $tag => $key) {
            $run[$key] += count($case->{$tag});
        }
    }
    $report['runs'][] = $run;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
