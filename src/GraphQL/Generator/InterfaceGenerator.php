<?php

declare(strict_types=1);

namespace Kolah\GraphQL\Generator;

use Kolah\GraphQL\Generator\Data\InterfaceTypeDefinition;
use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\PhpFile;

class InterfaceGenerator implements GeneratesCode
{
    public function build(TypeManager $typeManager, string $outputDirectory): void
    {
        foreach ($typeManager->getInterfaceTypes() as $definition) {
            $this->buildSingle($definition, $outputDirectory);
        }
    }

    protected function buildSingle(InterfaceTypeDefinition $definition, string $outputDirectory): void
    {
        $file = new PhpFile();
        $file->addComment('This file is auto-generated.');

        $fqcn = $definition->getGeneratedClassName();
        $namespaceName = Helpers::extractNamespace($fqcn);

        $namespace = $file->addNamespace($namespaceName);

        $className = $namespace->unresolveName($fqcn);
        $interface = $namespace->addInterface($className);

        foreach ($definition->getGraphQLDefinition()->fields as $field) {
            // Add the getter method.
            $method = $interface->addMethod(Util::replaceTokens('get{name}', [
                'name' => ucfirst($field->name->value),
            ]));

            $method->setReturnType(Util::replaceTokens('{namespace}\\{class}', [
                'namespace' => $namespace,
                'class' => $field->type,
            ]));
        }

        file_put_contents("$outputDirectory/Types/$className.php", (string) $file);
    }
}
