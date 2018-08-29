<?php

namespace Kolah\GraphQL\Generator;

use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\TypeNode;
use Kolah\GraphQL\Client\OutputObject;
use Kolah\GraphQL\Generator\Data\ObjectTypeDefinition;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\PhpFile;
use PhpOption\None;
use PhpOption\Option;

class OutputTypeGenerator implements GeneratesCode
{
    public function build(TypeManager $typeManager, string $outputDirectory): void
    {
        foreach ($typeManager->getOutputTypes() as $definition) {
            $this->buildSingle($definition, $outputDirectory, $typeManager);
        }
    }

    protected function buildSingle(ObjectTypeDefinition $definition, string $outputDirectory, TypeManager $typeManager): void
    {
        $file = new PhpFile();
        $file->addComment('This file is auto-generated.');

        $fqcn = $definition->getGeneratedClassName();
        $namespaceName = Helpers::extractNamespace($fqcn);

        $namespace = $file->addNamespace($namespaceName);

        $className = $namespace->unresolveName($fqcn);

        $class = $namespace->addClass($className);

        $namespace->addUse(None::class);
        $namespace->addUse(Option::class);
        $namespace->addUse(OutputObject::class);

        $class->addExtend(OutputObject::class);

        $this->addImplements($class, $definition->getGraphQLDefinition(), $typeManager);
        $this->addConstructor($class, $definition->getGraphQLDefinition());
        $this->addProperties($class, $definition->getGraphQLDefinition(), $typeManager);
        $this->addBuildFromArray($class, $definition->getGraphQLDefinition(), $typeManager);

        file_put_contents("$outputDirectory/Types/$className.php", (string)$file);
    }

    private function addImplements(ClassType $class, ObjectTypeDefinitionNode $node, TypeManager $typeManager): void
    {
        $namespace = $class->getNamespace();
        foreach ($node->interfaces as $interface) {
            $interfaceName = $typeManager->getDefinitionFor($interface->name->value)->getSubstitutedByClassName();
            $namespace->addUse($interfaceName);
            $class->addImplement($interfaceName);
        }
    }

    private function addProperties(ClassType $class, ObjectTypeDefinitionNode $node, TypeManager $typeManager): void
    {
        $namespace = $class->getNamespace();
        foreach ($node->fields as $field) {
            $fieldName = $field->name->value;

            $typeName = $typeManager->getPhpType($field->type);

            if ($typeManager->isNonScalar($field->type) && false === in_array($typeName, $namespace->getUses())) {
                $namespace->addUse($typeName);
            }

            $class->addProperty($fieldName)
                ->setVisibility('protected')
                ->addComment(Util::replaceTokens('@var {type}', [
                    'type' => $namespace->unresolveName($typeManager->getPhpDocType($field->type)) . '|Option',
                ]));

            $getterName = Util::replaceTokens('get{name}', [
                'name' => ucfirst($field->name->value),
            ]);

            $getterMethod = $class->addMethod($getterName);

            $getterMethod->setBody(Util::replaceTokens('return $this->{fieldName}->get();', ['fieldName' => $fieldName]));
            $getterMethod->setReturnType($typeManager->getPhpType($field->type));
            $getterMethod->setReturnNullable($typeManager->allowsNull($field->type));
        }
    }

    protected function addBuildFromArray(ClassType $outputObject, ObjectTypeDefinitionNode $objectNode, TypeManager $typeManager)
    {
        $method = $outputObject->addMethod('fromArray')
            ->setStatic(true);

        $parameter = $method->addParameter('fields');
        $parameter->setTypeHint('array');

        $setters = [];

        // @TODO This is going to require actually having everything else ready in a type registry
        foreach ($objectNode->fields as $field) {
            $setters[] = $this->buildSetCall($field->type, $field->name->value, $typeManager);
        }

        $setters = implode("\n", $setters);

        $method->setBody(
            <<<BODY
\$instance = new static();
        
$setters

return \$instance;
BODY
        );
    }

    protected function buildSetCall(TypeNode $type, string $field, TypeManager $typeManager): string
    {
        if ($type instanceof ListTypeNode) {
            return $this->buildListSetCall($type, $field, $typeManager);
        }

        if ($type instanceof NonNullTypeNode) {
            // I need to dig deeper to get to the named type of the non-null
            if ($type->type instanceof ListTypeNode) {
                return $this->buildListSetCall($type->type, $field, $typeManager);
            } elseif ($type->type instanceof NamedTypeNode) {
                return $this->buildNamedSetCall($type->type, $field, $typeManager);
            }
        }

        if ($type instanceof NamedTypeNode) {
            return $this->buildNamedSetCall($type, $field, $typeManager);
        }

        throw new \Exception('Unable to build a setter for the given type node');
    }

    protected function buildNamedSetCall(NamedTypeNode $type, string $field, TypeManager $typeManager): string
    {
        if ($typeManager->isNonScalar($type)) {
            switch ($typeManager->getTypeOf($type->name->value)) {
                case TypeManager::ENUM_TYPE:
                    return sprintf(
                        '$instance->enumFromArray($fields, \'%s\', %s::class);',
                        $field,
                        $type->name->value
                    );
                case TypeManager::OUTPUT_TYPE:
                    return sprintf(
                        '$instance->instanceFromArray($fields, \'%s\', %s::class);',
                        $field,
                        $type->name->value
                    );
                case TypeManager::SCALAR_TYPE:
                    return sprintf(
                        '$instance->customScalarFromArray($fields, \'%s\', %s::class);',
                        $field,
                        $type->name->value
                    );
                case TypeManager::UNION_TYPE:
                    // @TODO
                default:
                    return '';
            }
        }

        return sprintf('$instance->scalarFromArray($fields, \'' . $field . '\');');
    }

    protected function buildListSetCall(ListTypeNode $type, string $field, TypeManager $typeManager): string
    {
        $innerType = $type->type;
        if ($type->type instanceof NonNullTypeNode) {
            $innerType = $type->type->type;
        }

        if ($typeManager->isNonScalar($innerType)) {
            // If it is a non-scalar, I need to map across the array handling creation
            $innerTypeName = $innerType->name->value;
            switch ($typeManager->getTypeOf($innerType->name->value)) {
                case TypeManager::ENUM_TYPE:
                    return <<<SET
\$instance->listFromArray(\$fields, '$field', function (\$val) { 
    return $innerTypeName::fromString(\$val);
});
SET;
                case TypeManager::OUTPUT_TYPE:
                    return <<<SET
\$instance->listFromArray(\$fields, '$field', function(\$val) {
    return $innerTypeName::fromArray(\$val);
});
SET;
                case TypeManager::SCALAR_TYPE:
                    return <<<SET
\$instance->listFromArray(\$fields, '$field', function(\$val) {
    return $innerTypeName::parse(\$val);
});
SET;

                case TypeManager::UNION_TYPE:
                default:
                    return '';
            }
        } else {
            // I have an array of scalars, so I can treat the setting as if it was a single scalar
            return sprintf('$instance->scalarFromArray($fields, \'%s\');', $field);
        }
    }

    protected function addConstructor(ClassType $inputObject, ObjectTypeDefinitionNode $outputNode): void
    {
        $method = $inputObject->addMethod('__construct');
        $method->setVisibility('private');

        $sets = [];
        foreach ($outputNode->fields as $field) {
            $sets[] = sprintf("\$this->%s = None::create();", $field->name->value);
        }

        $setBody = implode("\n", $sets);
        $method->setBody($setBody);
    }
}
