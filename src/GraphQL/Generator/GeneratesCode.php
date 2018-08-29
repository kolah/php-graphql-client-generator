<?php

namespace Kolah\GraphQL\Generator;

interface GeneratesCode
{
    public function build(TypeManager $typeManager, string $outputDirectory): void;
}
