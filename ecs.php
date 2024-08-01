<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ArrayNotation\ArraySyntaxFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

return static function (ECSConfig $ecsConfig): void
{
    $ecsConfig->sets([
        SetList::STRICT,
        SetList::CLEAN_CODE,
        SetList::PSR_12,
    ]);

    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests/Codeception'
    ]);

    $ecsConfig->skip([
        __DIR__ . '/tests/Codeception/Support/_generated',
    ]);

    $ecsConfig->ruleWithConfiguration(ArraySyntaxFixer::class, ['syntax' => 'short']);

    $ecsConfig->cacheDirectory(__DIR__ . '/var/cache/ecs');
    $ecsConfig->parallel();

};
