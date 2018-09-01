<?php

namespace Kolah\GraphQL\Generator;

use GraphQL\Language\AST\InputValueDefinitionNode;
use Kolah\GraphQL\Client\InputObject;
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
        $namespace->addUse(InputObject::class);
        $class->addExtend(InputObject::class);

        $this->addConstructor($class, $definition, $typeManager);
        $this->addConstants($class, $definition, $typeManager);

        file_put_contents("$outputDirectory/Types/$className.php", (string)$file);
    }

    protected function addConstants(ClassType $inputObject, InputTypeDefinition $definition, TypeManager $typeManager): void
    {
        $inputNode = $definition->getGraphQLDefinition();
        $namespace = $inputObject->getNamespace();

        foreach ($inputNode->fields as $field) {
            $typeName = $typeManager->getPhpType($field->type);
            $constantName = strtoupper(Util::fromCamelCaseToUnderscore($field->name->value));
            $inputObject->addConstant($constantName, $field->name->value);

            if ($typeManager->isNonScalar($field) && false === in_array($typeName, $namespace->getUses())) {
                $namespace->addUse($typeName);
            }
        }
    }

    protected function addConstructor(ClassType $inputObject, InputTypeDefinition $definition, TypeManager $typeManager): void
    {
        $namespace = $inputObject->getNamespace();
        $inputNode = $definition->getGraphQLDefinition();
        $method = $inputObject->addMethod('__construct');
        $sets = [];
        $constructorArgumentCount = 0;
        foreach ($inputNode->fields as $field) {
            if ($typeManager->allowsNull($field->type) || null !== $field->defaultValue) {
                $this->addOptionalArguments($inputObject, $field, $typeManager);

                continue;
            }

            $constructorArgumentCount++;
            $parameter = $method->addParameter($field->name->value);
            $typeName = $typeManager->getPhpType($field->type);

            if ($typeManager->isNonScalar($field)) {
                $namespace->addUse($typeName);
            }

            $constantName = strtoupper(Util::fromCamelCaseToUnderscore($field->name->value));
            $parameter->setTypeHint($typeName);

            $sets[] = Util::replaceTokens('$this->data[self::{constantName}] = ${variableName};', [
                'constantName' => $constantName,
                'variableName' => $field->name->value
            ]);
        }

        $method->setBody(implode("\n", $sets));

        if ($constructorArgumentCount === 0) {
            $inputObject->removeMethod('__construct');
        }
    }

    protected function addOptionalArguments(ClassType $inputObject, InputValueDefinitionNode $field, TypeManager $typeManager): void
    {
        $namespace = $inputObject->getNamespace();
        $constantName = strtoupper(Util::fromCamelCaseToUnderscore($field->name->value));
        $methodName = ucfirst($field->name->value);
        $method = $inputObject->addMethod(Util::replaceTokens('with{methodName}', [
            'methodName' => $methodName
        ]));
        $method->setVisibility('public');
        $parameter = $method->addParameter('value');

        $typeName = $typeManager->getPhpType($field->type);

        if ($typeManager->isNonScalar($field)) {
            $namespace->addUse($typeName);
        }

        $parameter->setTypeHint($typeName);
        $parameter->setNullable(true);

        $body = <<<BODY
if (is_null(\$value)) {
    return \$this;
}
\$this->data[self::$constantName] = \$value;

return \$this;
BODY;


        $method->addBody($body);
    }
}
