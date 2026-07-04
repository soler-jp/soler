<?php

/**
 * テストソースが登録している仕訳を日本式の1行スタイルで標準出力に表示する。
 *
 * 使い方:
 *   vendor/bin/sail php tests/Tools/render-journals.php tests/Feature/FiscalYearRolloverTest.php [...]
 *   vendor/bin/sail php tests/Tools/render-journals.php tests/Feature
 */
require __DIR__.'/../../vendor/autoload.php';

use Tests\Tools\JournalSourceRenderer;

$paths = array_slice($argv, 1);

if ($paths === []) {
    fwrite(STDERR, "使い方: php tests/Tools/render-journals.php <テストファイルまたはディレクトリ>...\n");
    exit(1);
}

$files = [];

foreach ($paths as $path) {
    if (is_dir($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }
    } elseif (is_file($path)) {
        $files[] = $path;
    } else {
        fwrite(STDERR, "ファイルが見つかりません: {$path}\n");
        exit(1);
    }
}

sort($files);

$renderer = new JournalSourceRenderer;

foreach ($files as $file) {
    $output = $renderer->renderFile($file);

    if ($output !== '') {
        echo $output."\n";
    }
}
