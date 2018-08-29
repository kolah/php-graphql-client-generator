<?php

namespace Kolah\GraphQL\Generator;

use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Type\Definition\BooleanType;
use GraphQL\Type\Definition\FloatType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\ScalarType;
use Kolah\GraphQL\Client\Collection;
use Kolah\GraphQL\Generator\Data\EnumTypeDefinition;
use Kolah\GraphQL\Generator\Data\InputTypeDefinition;
use Kolah\GraphQL\Generator\Data\InterfaceTypeDefinition;
use Kolah\GraphQL\Generator\Data\OperationDefinition;
use Kolah\GraphQL\Generator\Data\ObjectTypeDefinition;
use Kolah\GraphQL\Generator\Data\ScalarTypeDefinition;
use Kolah\GraphQL\Generator\Data\TypeDefinition;

final class TypeManager
{
    const SCALAR_TYPE = 'SCALAR';
    const ENUM_TYPE = 'ENUM';
    const UNION_TYPE = 'UNION';
    const INPUT_TYPE = 'INPUT_TYPE';
    const OUTPUT_TYPE = 'OUTPUT_TYPE';
    const INTERFACE_TYPE = 'INTERFACE_TYPE';

    /** @var TypeDefinition[] */
    private $all = [];

    /** @var ScalarTypeDefinition[] */
    private $customScalars = [];

    /** @var EnumTypeDefinition[] */
    private $enums = [];

    /** @var TypeDefinition[] */
    private $unions = [];

    /** @var InputTypeDefinition[] */
    private $inputTypes = [];

    /** @var ObjectTypeDefinition[] */
    private $outputTypes = [];

    /** @var InterfaceTypeDefinition[] */
    private $interfaceTypes = [];

    /** @var OperationDefinition[] */
    private $queries = [];

    /** @var OperationDefinition[] */
    private $mutations = [];

    /** @var string */
    private $rootNamespace;

    /** @var array */
    private $typeMap = [];

    public function __construct(string $rootNamespace, array $typeMap = [])
    {
        $this->rootNamespace = $rootNamespace;
        $this->typeMap = $typeMap;
    }

    /**
     * @param $name
     *
     * @return null|string
     */
    public function getTypeOf($name): ?string
    {
        if (array_key_exists($name, $this->customScalars)) {
            return self::SCALAR_TYPE;
        } elseif (array_key_exists($name, $this->enums)) {
            return self::ENUM_TYPE;
        } elseif (array_key_exists($name, $this->unions)) {
            return self::UNION_TYPE;
        } elseif (array_key_exists($name, $this->inputTypes)) {
            return self::INPUT_TYPE;
        } elseif (array_key_exists($name, $this->outputTypes)) {
            return self::OUTPUT_TYPE;
        } elseif (array_key_exists($name, $this->interfaceTypes)) {
            return self::INTERFACE_TYPE;
        }

        return null;
    }

    /**
     * @param TypeNode|Node $node
     * @return TypeDefinition|null
     */
    public function getDefinitionByNode(Node $node): ?TypeDefinition
    {
        if ($node instanceof NonNullTypeNode) {
            return $this->getDefinitionByNode($node->type);
        }

        if (false == $this->isNonScalar($node)) {
            return null;
        }

        if ($node instanceof NamedTypeNode) {
            return $this->getDefinitionFor($node->name->value);
        }

        return null;
    }

    public function getDefinitionFor(string $name): ?TypeDefinition
    {
        if (array_key_exists($name, $this->customScalars)) {
            return $this->customScalars[$name];
        } elseif (array_key_exists($name, $this->enums)) {
            return $this->enums[$name];
        } elseif (array_key_exists($name, $this->unions)) {
            return $this->unions[$name];
        } elseif (array_key_exists($name, $this->inputTypes)) {
            return $this->inputTypes[$name];
        } elseif (array_key_exists($name, $this->outputTypes)) {
            return $this->outputTypes[$name];
        } elseif (array_key_exists($name, $this->interfaceTypes)) {
            return $this->interfaceTypes[$name];
        }

        return null;
    }

    public function registerScalar(string $name, string $class, ScalarTypeDefinitionNode $definition): void
    {
        $typeDefinition = new ScalarTypeDefinition($name, $definition, $class, $this->getMapping($name));
        $this->all[$name] = $typeDefinition;
        $this->customScalars[$name] = $typeDefinition;
    }

    public function registerEnum(string $name, string $class, EnumTypeDefinitionNode $definition): void
    {
        $typeDefinition = new EnumTypeDefinition($name, $definition, $class, $this->getMapping($name));
        $this->all[$name] = $typeDefinition;
        $this->enums[$name] = $typeDefinition;
    }

    public function registerUnion(string $name, string $class, UnionTypeDefinitionNode $definition): void
    {
        $typeDefinition = new TypeDefinition($name, $definition, $class, $this->getMapping($name));
        $this->all[$name] = $typeDefinition;
        $this->unions[$name] = $typeDefinition;
    }

    public function registerInputType(string $name, string $class, InputObjectTypeDefinitionNode $definition): void
    {
        $typeDefinition = new InputTypeDefinition($name, $definition, $class, $this->getMapping($name));
        $this->all[$name] = $typeDefinition;
        $this->inputTypes[$name] = $typeDefinition;
    }

    public function registerOutputType(string $name, string $class, ObjectTypeDefinitionNode $definition): void
    {
        $typeDefinition = new ObjectTypeDefinition($name, $definition, $class, $this->getMapping($name));
        $this->all[$name] = $typeDefinition;
        $this->outputTypes[$name] = $typeDefinition;
    }

    public function registerInterface(string $name, string $class, InterfaceTypeDefinitionNode $definition): void
    {
        $typeDefinition = new TypeDefinition($name, $definition, $class, $this->getMapping($name));
        $this->all[$name] = $typeDefinition;
        $this->interfaceTypes[$name] = $typeDefinition;
    }

    public function registerQuery(string $name, FieldDefinitionNode $definition): void
    {
        $this->queries[$name] = new OperationDefinition($name, $definition);
    }

    public function registerMutation(string $name, FieldDefinitionNode $definition): void
    {
        $this->mutations[$name] = new OperationDefinition($name, $definition);
    }

    public function registerNode(DefinitionNode $definition): void
    {
        switch (true) {
            case $definition instanceof ObjectTypeDefinitionNode:
                if ($definition->name->value === 'Query') {
                    /** @var FieldDefinitionNode $field */
                    foreach ($definition->fields as $field) {
                        $this->registerQuery($field->name->value, $field);
                    }
                    break;
                }
                if ($definition->name->value === 'Mutation') {
                    /** @var FieldDefinitionNode $field */
                    foreach ($definition->fields as $field) {
                        $this->registerMutation($field->name->value, $field);
                    }
                    break;
                }

                $this->registerOutputType($this->nodeName($definition), $this->nodeClass($definition), $definition);
                break;
            case $definition instanceof InputObjectTypeDefinitionNode:
                $this->registerInputType($this->nodeName($definition), $this->nodeClass($definition), $definition);
                break;
            case $definition instanceof ScalarTypeDefinitionNode:
                $this->registerScalar($this->nodeName($definition), $this->nodeClass($definition), $definition);
                break;
            case $definition instanceof EnumTypeDefinitionNode:
                $this->registerEnum($this->nodeName($definition), $this->nodeClass($definition), $definition);
                break;
            case $definition instanceof UnionTypeDefinitionNode:
                $this->registerUnion($this->nodeName($definition), $this->nodeClass($definition), $definition);
                break;
            case $definition instanceof InterfaceTypeDefinitionNode:
                $this->registerInterface($this->nodeName($definition), $this->nodeClass($definition), $definition);
                break;
        }
    }

    /**
     * @return ScalarTypeDefinition[]
     */
    public function getCustomScalars(): array
    {
        return $this->customScalars;
    }

    /**
     * @return EnumTypeDefinition[]
     */
    public function getEnums(): array
    {
        return $this->enums;
    }

    /**
     * @return TypeDefinition[]
     */
    public function getUnions(): array
    {
        return $this->unions;
    }

    /**
     * @return InputTypeDefinition[]
     */
    public function getInputTypes(): array
    {
        return $this->inputTypes;
    }

    /**
     * @return ObjectTypeDefinition[]
     */
    public function getOutputTypes(): array
    {
        return $this->outputTypes;
    }

    /**
     * @return InterfaceTypeDefinition[]
     */
    public function getInterfaceTypes(): array
    {
        return $this->interfaceTypes;
    }

    /**
     * @return OperationDefinition[]
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * @return OperationDefinition[]
     */
    public function getMutations(): array
    {
        return $this->mutations;
    }

    /**
     * @return string
     */
    public function getRootNamespace(): string
    {
        return $this->rootNamespace;
    }

    /**
     * @param string $namespace
     * @param Node|NamedTypeNode $node
     *
     * @return string
     */
    public function getNamedPhpType(Node $node): string
    {
        switch ($node->name->value) {
            case ScalarType::INT:
                return 'int';
                break;
            case ScalarType::STRING:
                return 'string';
                break;
            case ScalarType::BOOLEAN:
                return 'bool';
                break;
            case ScalarType::ID:
                return 'string';
                break;
            case ScalarType::FLOAT:
                return 'float';
                break;
            default:
                return $this->all[$node->name->value]->getSubstitutedByClassName();
        }
    }

    /**
     * @param Node|TypeNode $node
     * @return bool
     */
    public function allowsNull(Node $node): bool
    {
        return !$node instanceof NonNullTypeNode;
    }

    /**
     * @param TypeNode|Node $node
     * @return string
     */
    public function getPhpType(Node $node): string
    {
        switch (true) {
            case $node instanceof NonNullTypeNode:
                return self::getPhpType($node->type);
            case $node instanceof ListTypeNode:
                return Collection::class;
            case $node instanceof IntType:
                return 'int';
            case $node instanceof FloatType:
                return 'float';
            case $node instanceof BooleanType:
                return 'bool';
            case $node instanceof NamedTypeNode:

                return self::getNamedPhpType($node);
            default:
                return 'string';
        }
    }

    /**
     * @param TypeNode|Node $node
     * @return string
     */
    public function getPhpDocType(Node $node): string
    {
        switch (true) {
            case $node instanceof NonNullTypeNode:
                $type = self::getPhpDocType($node->type);
                break;

            case $node instanceof ListTypeNode:
                $nestedType = self::getPhpDocType($node->type);

                $type = implode('|', array_map(function ($type) {
                    return $type . '[]';
                }, explode('|', $nestedType)));
                break;

            case $node instanceof NamedTypeNode:
                $type = self::getNamedPhpType($node);
                break;

            default:
                $type = 'mixed';
        }

        return $type;
    }

    /**
     * @param NamedTypeNode|TypeNode|Node $node
     * @return bool
     */
    public function isNonScalar(Node $node): bool
    {
        if ($node instanceof InputValueDefinitionNode) {
            return $this->isNonScalar($node->type);
        }
        if ($node instanceof NonNullTypeNode) {
            return $this->isNonScalar($node->type);
        }
        if ($node instanceof ListTypeNode) {
            return true;
        }

        switch ($node->name->value) {
            case ScalarType::INT:
            case ScalarType::STRING:
            case ScalarType::BOOLEAN:
            case ScalarType::ID:
            case ScalarType::FLOAT:
                return false;
            default:
                return true;
        }
    }

    private function getMapping(string $graphQLType): ?string
    {
        if (!array_key_exists($graphQLType, $this->typeMap)) {
            return null;
        }

        return $this->typeMap[$graphQLType];
    }

    private function nodeName(DefinitionNode $definitionNode): string
    {
        /** @noinspection PhpUndefinedFieldInspection */
        return $definitionNode->name->value;
    }

    private function nodeClass(DefinitionNode $definitionNode): string
    {
        /** @noinspection PhpUndefinedFieldInspection */
        return $this->rootNamespace . '\\Types\\' . $definitionNode->name->value;
    }
}
