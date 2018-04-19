<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('lib/composer')
    ->in(__DIR__)
;

return PhpCsFixer\Config::create()
    ->setRules([
        'native_function_invocation' => true
    ])
    ->setFinder($finder)
;