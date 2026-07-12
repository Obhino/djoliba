<?php
$cmds = [
    'weasyprint --version',
    'python -m weasyprint --version',
    'py -m weasyprint --version',
    'where weasyprint',
    'where python'
];
echo "<pre>";
foreach ($cmds as $cmd) {
    echo "$ $cmd\n";
    $output = [];
    $retval = -1;
    exec($cmd, $output, $retval);
    echo "Exit code: $retval\n";
    echo implode("\n", $output) . "\n\n";
}
echo "</pre>";
