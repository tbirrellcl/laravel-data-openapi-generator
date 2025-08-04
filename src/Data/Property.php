<?php

namespace Xolvio\OpenApiGenerator\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data as LaravelData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema as EloquentSchema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Doctrine\DBAL\Types\Type;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Object_;

class Property extends Data
{
    public static function mapCastType($castType)
    {
        $mapping = [
            'integer' => 'int',
            'int' => 'int',
            'real' => 'float',
            'float' => 'float',
            'double' => 'float',
            'decimal' => 'float',
            'string' => 'string',
            'boolean' => 'bool',
            'bool' => 'bool',
            'object' => 'object',
            'array' => 'array',
            'json' => 'array',
            'collection' => 'array',
            'date' => 'CarbonImmutable',
            'datetime' => 'CarbonImmutable',
            'timestamp' => 'CarbonImmutable',
        ];

        // Handle custom casts (like datetime:Y-m-d)
        if (Str::contains($castType, ':')) {
            $baseType = Str::before($castType, ':');
            return $mapping[$baseType] ?? 'mixed';
        }

        return $mapping[$castType] ?? 'mixed';
    }

    protected const typeMapping = [
        'string' => 'string',
        'text' => 'string',
        'longtext' => 'string',
        'mediumtext' => 'string',
        'varchar' => 'string',
        'char' => 'string',
        'integer' => 'int',
        'int' => 'int',
        'bigint' => 'int',
        'smallint' => 'int',
        'tinyint' => 'int',
        'decimal' => 'float',
        'double' => 'float',
        'float' => 'float',
        'boolean' => 'bool',
        'tinyint(1)' => 'bool',
        'date' => 'CarbonImmutable',
        'datetime' => 'CarbonImmutable',
        'timestamp' => 'CarbonImmutable',
        'time' => 'CarbonImmutable',
        'json' => 'array',
        'jsonb' => 'array',
    ];

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
     * @return Collection<int,self>
     */
    public static function fromDataClass(string $class): Collection
    {
        if (is_a($class, Model::class, true)) {
            return self::fromModelClass($class);
        }

        if (! is_a($class, LaravelData::class, true)) {
            throw new RuntimeException('Class does not extend LaravelData');
        }

        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        return self::collect(
            array_map(
                fn(ReflectionProperty $property) => self::fromProperty($property),
                $properties
            ),
            Collection::class
        );
    }

    public static function fromModelClass(string $modelClass)
    {
        if (!class_exists($modelClass)) {
            throw new \Exception("Model {$modelClass} does not exist");
            return;
        }

        $reflection = new ReflectionClass($modelClass);
        $model = new $modelClass;

        // Get model properties
        $tableName = $model->getTable();
        // $fillable = self::getModelProperty($model, 'fillable');
        $hidden = self::getModelProperty($model, 'hidden');
        $casts = self::getModelProperty($model, 'casts');

        // // Get table columns
        $columns = self::getTableColumns($tableName, $hidden);

        // // Get relationships
        $relationships = self::getModelRelationships($reflection, $hidden);


        $properties = [];

        // Add model columns as properties
        foreach ($columns as $column) {

            $type = self::getColumnType($tableName, $column);

            // Apply cast types if defined
            if (isset($casts[$column])) {
                $type = self::mapCastType($casts[$column]);
            }

            $nullable = self::isColumnNullable($tableName, $column);
            $typeHint = $nullable ? "?{$type}" : $type;


            $properties[] = new self(
                name: $column,
                type: Schema::fromBuiltin($type, $nullable),
                required: !$nullable,
            );
        }


        // Add relationships as properties
        foreach ($relationships as $relationName => $relationData) {


            if (in_array($relationData['type'], ['belongsToMany', 'morphMany', 'hasMany'])) {
                $properties[] = new self(
                    name: $relationName,
                    type: Schema::fromModelCollection('App\\Models\\' . $relationData['models'][0], true),
                    required: false,
                );
            } elseif (in_array($relationData['type'], ['morphTo'])) {
                $properties[] = new self(
                    name: $relationName,
                    type: Schema::fromModel('App\\Models\\' . $type, true),

                    required: false,
                );
            } else {
                $properties[] = new self(
                    name: $relationName,
                    type: Schema::fromBuiltin($relationData['models'][0], true),
                    required: false,
                );
            }
        }

        return self::collect(
            $properties,
            Collection::class
        );
    }

    public static function getModelProperty($model, $property)
    {
        $reflection = new ReflectionClass($model);

        if ($reflection->hasProperty($property)) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            return $prop->getValue($model) ?? [];
        }

        return [];
    }

    public static  function getTableColumns($tableName, $hidden)
    {
        return array_filter(EloquentSchema::getColumnListing($tableName), fn($column) => !in_array($column, $hidden));
    }

    public static  function getColumnType($tableName, $columnName)
    {
        $columnType = EloquentSchema::getColumnType($tableName, $columnName);
        return self::typeMapping[$columnType] ?? 'mixed';
    }

    public static  function getModelRelationships(ReflectionClass $reflection, $hidden)
    {
        $relationships = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Skip magic methods, getters, setters, etc.
            if (
                $method->isStatic() ||
                $method->getNumberOfParameters() > 0 ||
                Str::startsWith($method->getName(), ['get', 'set', 'scope', '__']) ||
                in_array($method->getName(), ['save', 'delete', 'update', 'create', 'find', 'where'])
            ) {
                continue;
            }


            $methodName = $method->getName();
            if (in_array($methodName, $hidden)) {
                continue; // Skip hidden columns
            }

            $returnType = $method->getReturnType();

            $relationshipType = self::getRelationshipType($returnType);


            // Check if it's a relationship
            if ($relationshipType !== 'unknown') {
                try {

                    $doc = $reflection->getMethod($methodName)->getDocComment();
                    $docblock = DocBlockFactory::createInstance()->create($doc);
                    $returns = $docblock->getTagsByName('return');

                    foreach ($returns as $return) {
                        $valueType = $return->getType()->getValueType();

                        if ($valueType instanceof Compound) {
                            $relatedModelNames = [];
                            foreach ($valueType as $type) {
                                if ((string)$type !== 'null') {
                                    $relatedModelNames[] = $type->getFqsen()->getName();
                                }
                            }
                        } else if ($valueType instanceof Object_) {
                            $relatedModelNames = [$valueType->getFqsen()->getName()];
                        }
                    }



                    $relationships[$methodName] = [
                        'type' => $relationshipType,
                        'models' => $relatedModelNames,
                    ];
                } catch (\Error $e) {
                    dd($reflection->getName(), $methodName, $e);
                }
            }
        }

        return $relationships;
    }

    public static  function getRelationshipType($className)
    {

        switch ($className) {
            case 'Illuminate\Database\Eloquent\Relations\HasOne':
                return 'hasOne';
            case 'Illuminate\Database\Eloquent\Relations\HasMany':
                return 'hasMany';
            case 'Illuminate\Database\Eloquent\Relations\BelongsTo':
                return 'belongsTo';
            case 'Illuminate\Database\Eloquent\Relations\BelongsToMany':
                return 'belongsToMany';
            case 'Illuminate\Database\Eloquent\Relations\HasOneThrough':
                return 'hasOneThrough';
            case 'Illuminate\Database\Eloquent\Relations\HasManyThrough':
                return 'hasManyThrough';
            case 'Illuminate\Database\Eloquent\Relations\MorphTo':
                return 'morphTo';
            case 'Illuminate\Database\Eloquent\Relations\MorphOne':
                return 'morphOne';
            case 'Illuminate\Database\Eloquent\Relations\MorphMany':
                return 'morphMany';
            default:
                return 'unknown';
        }
    }


    public static  function isColumnNullable($tableName, $columnName)
    {
        try {
            $connection = EloquentSchema::getConnection();
            $schemaBuilder = $connection->getSchemaBuilder();

            // Get column information using Laravel's schema builder
            $columns = $schemaBuilder->getColumns($tableName);

            foreach ($columns as $column) {
                if ($column['name'] === $columnName) {
                    return $column['nullable'] ?? true;
                }
            }

            return true;
        } catch (\Exception $e) {
            dd($e);
            return true; // Default to nullable if we can't determine
        }
    }

    public static function fromProperty(ReflectionProperty $reflection): self
    {
        return new self(
            name: $reflection->getName(),
            type: Schema::fromReflectionProperty($reflection),
            required: ! $reflection->getType()?->allowsNull() ?? false,
        );
    }
}
