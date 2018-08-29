<?php

namespace Kolah\GraphQL\Generator;

use GraphQL\Language\Parser;

class Generator
{
    /** @var GeneratesCode[] */
    private $generators = [];

    public function addGenerator(GeneratesCode $generator)
    {
        $this->generators[] = $generator;
    }

    public function build(string $clientNamespace, string $schemaFilename, string $outputDirectory, array $typeMapping = []): void
    {
        $schema = file_get_contents($schemaFilename);
        $parsedSchema = Parser::parse($schema);
        $typeManager = new TypeManager($clientNamespace, $typeMapping);

        foreach ($parsedSchema->definitions as $definition) {
            $typeManager->registerNode($definition);
        }

        foreach ($this->generators as $generator) {
            $generator->build($typeManager, $outputDirectory);
        }
    }
}
