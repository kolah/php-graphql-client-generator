<?php

namespace Kolah\GraphQL\Generator;

use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NonNullTypeNode;
use Kolah\GraphQL\Client\FieldSelection;
use Kolah\GraphQL\Generator\Data\ObjectTypeDefinition;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;

class FieldSelectionGenerator implements GeneratesCode
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

        $namespaceName = $typeManager->getRootNamespace();
        $namespace = $file->addNamespace($namespaceName);

        $className = $definition->getGraphQLType() . 'FieldSelection';

        $class = $namespace->addClass($className);

        foreach ($definition->getGraphQLDefinition()->fields as $field) {
            $this->addField($field, $class, $typeManager);
        }

        // Add dependencies
        $namespace->addUse(FieldSelection::class);

        // Add inheritance
        $class->addExtend(FieldSelection::class);

        file_put_contents("$outputDirectory/$className.php", (string)$file);
    }

    protected function addField(FieldDefinitionNode $fieldNode, ClassType $selectionObject, TypeManager $typeManager): void
    {
        $type = $fieldNode->type;
        $namespace = $selectionObject->getNamespace();

        while ($type instanceof ListTypeNode || $type instanceof NonNullTypeNode) {
            $type = $type->type;
        }

        // Make the setter
        $method = $selectionObject->addMethod('with' . ucfirst($fieldNode->name->value));
        $method->setReturnType('self');

        $constantName = strtoupper(Util::fromCamelCaseToUnderscore($fieldNode->name->value));
        $fieldSelection = 'null';
        $args = 'null';
        $argumentSetters = [];

        if ($typeManager->getTypeOf($type->name->value) === TypeManager::OUTPUT_TYPE) {
            $fieldSelection = '$fieldSelection';
            $className = ucfirst($type->name->value) . 'FieldSelection';

            $parameter = $method->addParameter('fieldSelection');
            $parameter->setTypeHint($selectionObject->getNamespace()->getName() . '\\'. $className);

            $method->setReturnType('self');
        }

        foreach ($fieldNode->arguments as $argument) {
            $args = '$args';
            $argName = $argument->name->value;

            $argNodeType = $argument->type;
            while ($argNodeType instanceof ListTypeNode || $argNodeType instanceof NonNullTypeNode) {
                $argNodeType = $argNodeType->type;
            }

            // This is mixed because it is actually an option
            $allowsNull = $typeManager->allowsNull($argument);
            $type = $typeManager->getPhpType($argument->type);

            if ($typeManager->isNonScalar($argument)) {
                $namespace->addUse($type);
            }

            $parameter = $method->addParameter($argName);
            $parameter->setNullable($allowsNull);
            $parameter->setTypeHint($type);
            if ($allowsNull) {
                $parameter->setDefaultValue(null);
            }

            $argumentSetters[] = sprintf('null === $%s ?: $args[\'%s\'] = $%s;', $argName, $argName, $argName);
        }

        $body = "return \$this->withSpecifiedField(self::$constantName, $args, $fieldSelection);";

        if ($argumentSetters) {
            $body = "\n" . $body;
            // Add argument setters as needed before the return
            foreach ($argumentSetters as $argumentSetter) {
                $body = $argumentSetter . "\n" . $body;
            }

            $body = '$args = [];' . "\n\n" . $body;
        }

        // Set the body
        $method->setBody($body);

        // Add a constant
        $selectionObject->addConstant($constantName, $fieldNode->name->value);
    }
}
