<?php

namespace Kolah\GraphQL\Generator;

use Kolah\GraphQL\Client\GraphQLEnum;
use Kolah\GraphQL\Generator\Data\EnumTypeDefinition;
use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\PhpFile;

class EnumTypeGenerator implements GeneratesCode
{
    public function build(TypeManager $typeManager, string $outputDirectory): void
    {
        foreach ($typeManager->getEnums() as $definition) {
            $this->buildSingle($definition, $outputDirectory);
        }
    }

    protected function buildSingle(EnumTypeDefinition $typeDefinition, string $outputDirectory): void
    {
        $file = new PhpFile();
        $file->addComment('This file is auto-generated.');

        $fqcn = $typeDefinition->getGeneratedClassName();
        $namespaceName = Helpers::extractNamespace($fqcn);

        $namespace = $file->addNamespace($namespaceName);
        $namespace->addUse(GraphQLEnum::class);

        $className = $namespace->unresolveName($fqcn);

        $class = $namespace->addClass($className);
        $class->setExtends(GraphQLEnum::class);

        $enumConstReferenceList = [];
        foreach ($typeDefinition->getGraphQLDefinition()->values as $value) {
            // Add the constants to the class
            $enumValue = $value->name->value;
            $constantName = strtoupper($enumValue);
            $class->addConstant($constantName, $enumValue);

            $enumConstReference = 'self::' . $constantName;

            $method = $class->addMethod($constantName);
            $method->setStatic(true)
                ->setReturnType('self')
                ->setBody("return new static($enumConstReference);");

            $enumConstReferenceList[] = $enumConstReference;
        }

        $method = $class->addMethod('getOptions')
            ->setVisibility('public')
            ->setStatic(true)
            ->setReturnType('array');

        $method->setBody('return [' . implode(', ', $enumConstReferenceList) . '];');

        file_put_contents("$outputDirectory/Types/$className.php", (string)$file);
    }
}
