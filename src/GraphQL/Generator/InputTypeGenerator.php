<?php

namespace Kolah\GraphQL\Generator;

use GraphQL\Language\AST\InputValueDefinitionNode;
use Kolah\GraphQL\Generator\Data\InputTypeDefinition;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\PhpFile;

class InputTypeGenerator implements GeneratesCode
{
    public function build(TypeManager $typeManager, string $outputDirectory): void
    {
        foreach ($typeManager->getInputTypes() as $definition) {
            $this->buildSingle($definition, $outputDirectory, $typeManager);
        }
    }

    protected function buildSingle(InputTypeDefinition $definition, string $outputDirectory, TypeManager $typeManager): void
    {
        $file = new PhpFile();
        $file->addComment('This file is auto-generated.');

        $fqcn = $definition->getGeneratedClassName();
        $namespaceName = Helpers::extractNamespace($fqcn);

        $namespace = $file->addNamespace($namespaceName);

        $className = $namespace->unresolveName($fqcn);

        $class = $namespace->addClass($className);

        $this->addConstructor($class, $definition, $typeManager);
        $this->addProperties($class, $definition, $typeManager);

        file_put_contents("$outputDirectory/Types/$className.php", (string)$file);
    }

    protected function addProperties(ClassType $inputObject, InputTypeDefinition $definition, TypeManager $typeManager): void
    {
        $inputNode = $definition->getGraphQLDefinition();
        $namespace = $inputObject->getNamespace();

        foreach ($inputNode->fields as $field) {
            $typeName = $typeManager->getPhpType($field->type);

            if ($typeManager->isNonScalar($field) && false === in_array($typeName, $namespace->getUses())) {
                $namespace->addUse($typeName);
            }
            $property = $inputObject->addProperty($field->name->value);
            $property->addComment(Util::replaceTokens("@var {type}{null}", [
                'type' => $namespace->unresolveName($typeManager->getPhpDocType($field->type)),
                'null' => $typeManager->allowsNull($field->type) ? '|null' : '',
            ]));
        }
    }

    protected function addConstructor(ClassType $inputObject, InputTypeDefinition $definition, TypeManager $typeManager): void
    {
        $namespace = $inputObject->getNamespace();
        $inputNode = $definition->getGraphQLDefinition();
        $method = $inputObject->addMethod('__construct');
        $sets = [];

        /** @noinspection PhpParamsInspection */
        $fields = iterator_to_array($inputNode->fields);
        usort($fields, function (InputValueDefinitionNode $field1, InputValueDefinitionNode $field2) use ($typeManager) {
            $nullable1 = $typeManager->allowsNull($field1) || null !== $field1->defaultValue;
            $nullable2 = $typeManager->allowsNull($field2) || null !== $field2->defaultValue;

            return (int)$nullable1 <=> (int)$nullable2;
        });

        foreach ($fields as $field) {
            $parameter = $method->addParameter($field->name->value);
            $typeName = $typeManager->getPhpType($field->type);

            if ($typeManager->isNonScalar($field) && false === in_array($typeName, $namespace->getUses())) {
                $namespace->addUse($typeName);
            }

            $parameter->setTypeHint($typeName);

            if ($typeManager->allowsNull($field->type) || null !== $field->defaultValue) {
                $parameter->setNullable();
                $parameter->setDefaultValue(null);
            }

            $sets[] = Util::replaceTokens('$this->{propertyName} = ${variableName};', [
                'propertyName' => $field->name->value,
                'variableName' => $field->name->value
            ]);
        }

        $method->setBody(implode("\n", $sets));
    }
}
