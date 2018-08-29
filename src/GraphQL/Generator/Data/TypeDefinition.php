<?php

namespace Kolah\GraphQL\Generator\Data;

use GraphQL\Language\AST\DefinitionNode;

class TypeDefinition
{
    /** @var string */
    private $graphQLType;

    /** @var DefinitionNode */
    private $graphQLDefinition;

    /** @var string */
    private $generatedClassName;

    /** @var string|null */
    private $substitutedByClassName;

    public function __construct(string $graphQLType, DefinitionNode $graphQLDefinition, string $generatedClassName, ?string $substitutedByClassName = null)
    {
        $this->graphQLType = $graphQLType;
        $this->graphQLDefinition = $graphQLDefinition;
        $this->generatedClassName = $generatedClassName;
        $this->substitutedByClassName = $substitutedByClassName;
    }

    public function getGraphQLType(): string
    {
        return $this->graphQLType;
    }

    public function getGraphQLDefinition(): DefinitionNode
    {
        return $this->graphQLDefinition;
    }

    public function getGeneratedClassName(): string
    {
        return $this->generatedClassName;
    }

    public function getSubstitutedByClassName(): string
    {
        return $this->substitutedByClassName ?: $this->getGeneratedClassName();
    }
}
