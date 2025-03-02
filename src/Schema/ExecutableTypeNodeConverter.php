<?php

namespace Nuwave\Lighthouse\Schema;

use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use Nuwave\Lighthouse\Schema\AST\TypeNodeConverter;

class ExecutableTypeNodeConverter extends TypeNodeConverter
{
    /**
     * @var \Nuwave\Lighthouse\Schema\TypeRegistry
     */
    protected $typeRegistry;

    public function __construct(TypeRegistry $typeRegistry)
    {
        $this->typeRegistry = $typeRegistry;
    }

    /**
     * @param \GraphQL\Type\Definition\Type&\GraphQL\Type\Definition\NullableType $type
     */
    protected function nonNull($type): NonNull
    {
        return Type::nonNull($type);
    }

    /**
     * @template T of \GraphQL\Type\Definition\Type
     *
     * @param T|callable():T $type
     *
     * @return \GraphQL\Type\Definition\ListOfType<T>
     */
    protected function listOf($type): ListOfType
    {
        return Type::listOf($type);
    }

    protected function namedType(string $nodeName): Type
    {
        return Type::getStandardTypes()[$nodeName]
            ?? $this->typeRegistry->get($nodeName);
    }
}
