<?php

namespace Kolah\GraphQL\Generator\Data;

use GraphQL\Language\AST\FieldDefinitionNode;

class OperationDefinition
{
    /** @var string */
    private $name;

    /** @var FieldDefinitionNode */
    private $graphQLDefinition;

    public function __construct(string $name, FieldDefinitionNode $graphQLDefinition)
    {
        $this->name = $name;
        $this->graphQLDefinition = $graphQLDefinition;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getGraphQLDefinition(): FieldDefinitionNode
    {
        return $this->graphQLDefinition;
    }
}
