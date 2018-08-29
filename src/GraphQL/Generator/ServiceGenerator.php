<?php

declare(strict_types=1);

namespace Kolah\GraphQL\Generator;

use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Definition\WrappingType;
use Kolah\GraphQL\Client\Collection;
use Kolah\GraphQL\Client\GraphQLService;
use Kolah\GraphQL\Client\Mutation;
use Kolah\GraphQL\Client\Query;
use Kolah\GraphQL\Generator\Data\OperationDefinition;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;

class ServiceGenerator implements GeneratesCode
{
    public function build(TypeManager $typeManager, string $outputDirectory): void
    {
        $file = new PhpFile();
        $namespace = $file->addNamespace($typeManager->getRootNamespace());
        $namespaceName = Helpers::extractShortName($typeManager->getRootNamespace());
        $serviceName = $namespaceName . 'Service';

        $serviceObject = $namespace->addClass($serviceName);

        foreach ($typeManager->getQueries() as $definition) {
            $this->buildQueryMethods($serviceObject, $definition, $typeManager);
        }
        foreach ($typeManager->getMutations() as $definition) {
            $this->buildMutationMethods($serviceObject, $definition, $typeManager);
        }

        // Add service extension and use statements
        $serviceObject->addExtend(GraphQLService::class);

        // Add the dependencies
        $namespace->addUse(GraphQLService::class);
        $namespace->addUse(Query::class);
        $namespace->addUse(Mutation::class);

        file_put_contents("$outputDirectory/$serviceName.php", (string)$file);
    }

    /**
     * @param TypeNode|Node $node
     */
    protected function getSelectionArgument(Node $node, TypeManager $typeManager): ?string
    {
        if ($node instanceof NamedTypeNode && $typeManager->isNonScalar($node)) {
            return Util::replaceTokens('{namespace}\\{className}', [
                'namespace' => $typeManager->getRootNamespace(),
                'className' => ucfirst($node->name->value) . 'FieldSelection',
            ]);
        }

        if ($node instanceof ListTypeNode) {
            return $this->getSelectionArgument($node->type, $typeManager);
        }

        if ($node instanceof NonNullTypeNode) {
            return $this->getSelectionArgument($node->type, $typeManager);
        }

        return null;
    }

    protected function buildQueryMethods(ClassType $serviceObject, OperationDefinition $definition, TypeManager $typeManager): void
    {
        $field = $definition->getGraphQLDefinition();
        $method = $serviceObject->addMethod('query' . ucfirst($definition->getName()));
        $method->setVisibility('public');
        $method->setReturnType($typeManager->getPhpType($field->type));

        // Add the selection argument if it is an object type
        if ($selectionField = $this->getSelectionArgument($field->type, $typeManager)) {
            $method->addParameter('fieldSelection')
                ->setTypeHint($selectionField);
        }


        // Handle arguments
        foreach ($field->arguments as $argument) {
            $type = $typeManager->getPhpType($argument->type);
            $parameter = $method->addParameter($argument->name->value);
            $parameter->setTypeHint($type);
            if ($typeManager->allowsNull($argument->type)) {
                $parameter->setNullable(true);
            }
            $parameter->setDefaultValue(null);
        }

        // Add the body
        $method->setBody($this->buildQueryBody($serviceObject->getNamespace(), $definition, $field, $typeManager));
    }

    protected function buildMutationMethods(ClassType $serviceObject, OperationDefinition $definition, TypeManager $typeManager): void
    {
        $field = $definition->getGraphQLDefinition();
        $method = $serviceObject->addMethod('mutation' . ucfirst($definition->getName()));
        $method->setVisibility('public');
        $method->setReturnType($typeManager->getPhpType($field->type));

        // Handle arguments
        foreach ($field->arguments as $argument) {
            $type = $typeManager->getPhpType($argument->type);
            $parameter = $method->addParameter($argument->name->value);
            $parameter->setTypeHint($type)
                ->setNullable($typeManager->allowsNull($argument->type));
        }

        // Add the selection argument if it is an object type
        if ($selectionField = $this->getSelectionArgument($field->type, $typeManager)) {
            $method->addParameter('fieldSelection')
                ->setTypeHint($selectionField);
        }

        // Add the body
        $method->setBody($this->buildMutationBody($serviceObject->getNamespace(), $definition, $field, $typeManager));
    }

    /**
     * @param Node|ObjectType|InterfaceType|UnionType|ScalarType|InputObjectType|EnumType $type
     * @return null|string
     */
    protected function getDecodeType(Node $type): ?string
    {
        if ($type instanceof NamedTypeNode && $type->name->value !== 'ID') {
            return ucfirst($type->name->value);
        }

        // Both wrapping checks are necessary because of the odd class aliases
        if ($type instanceof ListTypeNode) {
            return $this->getDecodeType($type->type);
        }

        if ($type instanceof NonNullTypeNode) {
            return $this->getDecodeType($type->type);
        }

        if ($type instanceof WrappingType) {
            return $this->getDecodeType($type->getWrappedType());
        }

        return null;
    }

    protected function buildQueryBody(PhpNamespace $namespace, OperationDefinition $definition, FieldDefinitionNode $field, TypeManager $typeManager): string
    {
        $action = $field->name->value;
        $returnValue = $this->buildReturn($namespace, $definition, $field, $typeManager);

        $arguments = [];
        /** @var InputValueDefinitionNode $argument */
        foreach ($field->arguments as $argument) {
            $allowsNull = $typeManager->allowsNull($argument->type);
            if ($allowsNull) {
                $template = <<<BODY
if (\${parameterName} !== null) {
    \$arguments['{argumentKey}'] = \${parameterName};
}
BODY;
            } else {
                $template = '$arguments[\'{argumentKey}\'] = ${parameterName};';
            }
            $arguments[] = Util::replaceTokens($template, [
                'argumentKey' => $argument->name->value,
                'parameterName' => $argument->name->value,
            ]);
        }

        $arguments = implode("\n", $arguments);
        $body = <<<BODY

\$arguments = [];
 
$arguments
\$query = Query::withAction('$action', \$arguments, \$fieldSelection);

\$result = \$this->send(\$query->encode());

return $returnValue;
BODY;

        return $body;
    }


    protected function buildMutationBody(PhpNamespace $namespace, OperationDefinition $definition, FieldDefinitionNode $field, TypeManager $typeManager): string
    {
        $action = $field->name->value;
        $returnValue = $this->buildReturn($namespace, $definition, $field, $typeManager);

        $arguments = [];
        /** @var InputValueDefinitionNode $argument */
        foreach ($field->arguments as $argument) {
            $arguments[] = Util::replaceTokens('$arguments[\'{argumentKey}\'] = ${parameterName};', [
                'argumentKey' => $argument->name->value,
                'parameterName' => $argument->name->value,
            ]);
        }
        $arguments = implode("\n", $arguments);
        $body = <<<BODY

\$arguments = [];
 
$arguments
\$mutation = Mutation::withAction('$action', \$arguments, \$fieldSelection);

\$result = \$this->send(\$mutation->encode());

return $returnValue;
BODY;

        return $body;
    }


    protected function buildReturn(PhpNamespace $namespace, OperationDefinition $definition, FieldDefinitionNode $definitionNode, TypeManager $typeManager): string
    {
        $type = $definitionNode->type;

        if ($type instanceof ListTypeNode) {
            return $this->buildListTypeReturn($namespace, $definition, $type, $typeManager);
        } elseif ($type instanceof NonNullTypeNode) {
            if ($type->type instanceof ListTypeNode) {
                return $this->buildListTypeReturn($namespace, $definition, $type->type, $typeManager);
            } else {
                return $this->buildNamedTypeReturn($namespace, $definition, $type->type, $typeManager);
            }
        } elseif ($type instanceof NamedTypeNode) {
            return $this->buildNamedTypeReturn($namespace, $definition, $type, $typeManager);
        }

        return '\'\';';
    }

    protected function buildListTypeReturn(PhpNamespace $namespace, OperationDefinition $definition, ListTypeNode $type, TypeManager $typeManager): string
    {
        $namespace->addUse(Collection::class);
        $operationName = $definition->getName();
        $innerType = $type->type;
        if ($type->type instanceof NonNullTypeNode) {
            $innerType = $type->type->type;
        }

        if ($typeManager->isNonScalar($innerType)) {
            // If it is a non-scalar, I need to map across the array
            $innerTypeName = $typeManager->getPhpType($innerType);
            $resolvedName = $namespace->unresolveName($innerTypeName);
            switch ($typeManager->getTypeOf($innerType->name->value)) {
                case TypeManager::ENUM_TYPE:
                    return <<<SET
new Collection(array_map(function (\$val) {
    return $resolvedName::fromString(\$val);
}, \$result['$operationName']))
SET;
                case TypeManager::OUTPUT_TYPE:
                    return <<<SET
new Collection(array_map(function(\$val) {
    return $resolvedName::fromArray(\$val);
}, \$result['$operationName']))
SET;
                case TypeManager::SCALAR_TYPE:
                    return <<<SET
new Collection(array_map(function(\$val) {
    return $resolvedName::parse(\$val);
}, \$result['$operationName']))
SET;

                case TypeManager::UNION_TYPE:
                    // @TODO
                default:
                    return '';
            }
        } else {
            // I have an array of scalars, so I can treat the setting as if it was a single scalar
            return '$result';
        }
    }

    /**
     * @param Node|NamedTypeNode $type
     */
    protected function buildNamedTypeReturn(PhpNamespace $namespace, OperationDefinition $definition, Node $type, TypeManager $typeManager): string
    {
        $operationName = $definition->getName();
        if ($typeManager->isNonScalar($type)) {
            switch ($typeManager->getTypeOf($type->name->value)) {
                case TypeManager::ENUM_TYPE:
                    return $namespace->unresolveName($typeManager->getDefinitionFor($type->name->value)->getSubstitutedByClassName()) . "::fromString(\$result['$operationName'])";
                case TypeManager::OUTPUT_TYPE:
                    return $namespace->unresolveName($typeManager->getDefinitionFor($type->name->value)->getSubstitutedByClassName()) . "::fromArray(\$result['$operationName'])";
                case TypeManager::SCALAR_TYPE:
                    return $namespace->unresolveName($typeManager->getDefinitionFor($type->name->value)->getSubstitutedByClassName()) . "::parse(\$result['$operationName'])";
                case TypeManager::UNION_TYPE:
                    // @TODO
                default:
                    throw new \Exception('No way to determine return structure for type named ' . $type->name->value);
            }
        }

        return sprintf('$result');
    }
}
