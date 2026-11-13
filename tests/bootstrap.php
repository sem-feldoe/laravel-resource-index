<?php

declare(strict_types=1);

$loader = require getenv('RESOURCE_INDEX_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$loader->addPsr4('Atx\\ResourceIndex\\', dirname(__DIR__).'/src', true);
$loader->addPsr4('Atx\\ResourceIndex\\Tests\\', __DIR__, true);

$map = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src')) as $file) {
    if ($file->isFile() && 'php' === $file->getExtension()) {
        $relative = substr($file->getPathname(), strlen(dirname(__DIR__).'/src/'), -4);
        $map['Atx\\ResourceIndex\\'.str_replace('/', '\\', $relative)] = $file->getPathname();
    }
}
$loader->addClassMap($map);
