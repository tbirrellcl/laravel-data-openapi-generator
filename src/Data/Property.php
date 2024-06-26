<?php

namespace Xolvio\OpenApiGenerator\Data;

use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data as LaravelData;
use Spatie\LaravelData\DataCollection;

class Property extends Data
{
    public function __construct(
        protected string $name,
        public Schema $type,
        public bool $required = true,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return DataCollection<int,self>
     */
    public static function fromDataClass(string $class): DataCollection
    {
        if (! is_a($class, LaravelData::class, true)) {
            throw new RuntimeException('Class does not extend LaravelData');
        }

        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        /** @var DataCollection<int,self> */
        $collection = self::collect(
            array_map(
                fn (ReflectionProperty $property) => self::fromProperty($property),
                $properties
            ),
            DataCollection::class
        );

        return $collection;
    }

    public static function fromProperty(ReflectionProperty $reflection): self
    {
        return new self(
            name: $reflection->getName(),
            type: Schema::fromReflectionProperty($reflection),
            required: !$reflection->getType()?->allowsNull() ?? false,
        );
    }
}
