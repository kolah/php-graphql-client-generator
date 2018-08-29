<?php

namespace Kolah\GraphQL\Generator;

use Kolah\GraphQL\Client\Scalar;
use Kolah\GraphQL\Generator\Data\ScalarTypeDefinition;
use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\PhpFile;

class CustomScalarGenerator implements GeneratesCode
{
    public function build(TypeManager $typeManager, string $outputDirectory): void
    {
        foreach ($typeManager->getCustomScalars() as $definition) {
            $this->buildSingle($definition, $outputDirectory);
        }
    }

    protected function buildSingle(ScalarTypeDefinition $definition, string $outputDirectory): void
    {
        $file = new PhpFile();
        $file->addComment('This file is auto-generated.');

        $fqcn = $definition->getGeneratedClassName();
        $namespaceName = Helpers::extractNamespace($fqcn);

        $namespace = $file->addNamespace($namespaceName);
        $namespace->addUse(Scalar::class);

        $className = $namespace->unresolveName($fqcn);

        $class = $namespace->addClass($className);

        // Extend the default scalar class
        $class->addExtend(Scalar::class);

        file_put_contents("$outputDirectory/Types/$className.php", (string)$file);
    }
}
