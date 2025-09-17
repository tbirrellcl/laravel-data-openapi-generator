<?php

namespace Xolvio\OpenApiGenerator\Data;

use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\AbstractList;
use phpDocumentor\Reflection\Types\Compound;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Xolvio\OpenApiGenerator\Attributes\CustomContentType;

class Content extends Data
{
    public function __construct(
        /** @var string[] */
        protected array $types,
        public Schema $schema,
    ) {}

    public static function fromReflection(ReflectionNamedType $type, ReflectionFunction|ReflectionMethod $method): self
    {
        $types = self::typesFromReflection($type);

        // Expand DataCollection responses into array schemas that reference the underlying Data items.
        if ($type->getName() === DataCollection::class) {
            $schema = self::schemaFromDataCollection($type, $method);
            if ($schema !== null) {
                return new self(types: $types, schema: $schema);
            }
        }

        return new self(
            types: $types,
            schema: Schema::fromDataReflection($type, $method),
        );
    }

    public static function fromClass(string $class, ReflectionFunction|ReflectionMethod $method): self
    {
        $type = $method->getReturnType();

        return new self(
            types: self::typesFromReflection($type),
            schema: Schema::fromDataReflection($class),
        );
    }

    /**
     * @return array<int|string,mixed>
     */
    public function transform(
        null|TransformationContext|TransformationContextFactory $transformationContext = null,
    ): array {
        return collect($this->types)->mapWithKeys(
            fn (string $content_type) => [$content_type => parent::transform($transformationContext)]
        )->toArray();
    }

    /**
     * @return string[]
     */
    protected static function typesFromReflection(null|ReflectionNamedType|ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            /** @var class-string $name */
            $name       = $type->getName();
            $reflection = new ReflectionClass($name);

            $custom_content_attribute = $reflection->getAttributes(CustomContentType::class);

            if (count($custom_content_attribute) > 0) {
                return $custom_content_attribute[0]->getArguments()['type'];
            }
        }

        return ['application/json'];
    }

    protected static function schemaFromDataCollection(
        ReflectionNamedType $type,
        ReflectionFunction|ReflectionMethod $method,
    ): ?Schema {
        $doc_block = $method->getDocComment();

        if (! is_string($doc_block)) {
            return null;
        }

        // We rely on the return docblock to infer the generic type, e.g. DataCollection<ConversationData>.
        $docblock = DocBlockFactory::createInstance()->create($doc_block);
        $tag      = $docblock->getTagsByName('return')[0] ?? null;

        if ($tag === null) {
            return null;
        }

        $tag_type = $tag->getType();

        if ($tag_type instanceof Compound) {
            foreach ($tag_type as $single_type) {
                if ((string) $single_type !== 'null') {
                    $tag_type = $single_type;
                    break;
                }
            }
        }

        if (! $tag_type instanceof AbstractList) {
            return null;
        }

        // Resolve the generic argument to a concrete class so the schema can reference the correct Data object.
        $value_type = self::sanitizeTypeName((string) $tag_type->getValueType());
        $item_class = self::resolveClassName($value_type, $method);

        if ($item_class === null) {
            return null;
        }

        return new Schema(
            type: 'array',
            nullable: $type->allowsNull(),
            items: Schema::fromDataReflection($item_class),
        );
    }

    protected static function resolveClassName(
        string $class,
        ReflectionFunction|ReflectionMethod $method,
    ): ?string {
        $class = self::sanitizeTypeName($class);

        $class = ltrim($class, '\\');

        if (class_exists($class)) {
            return $class;
        }

        $namespaces = [];

        $imports = [];

        if ($method instanceof ReflectionMethod) {
            $declaring_class = $method->getDeclaringClass();
            $namespaces[]    = $declaring_class->getNamespaceName();
            $imports         = self::getUseStatementsForClass($declaring_class);
        } elseif ($method instanceof ReflectionFunction && ($scope = $method->getClosureScopeClass())) {
            $namespaces[] = $scope->getNamespaceName();
            $imports      = self::getUseStatementsForClass($scope);
        }

        // First check imported aliases, then fall back to the declaring namespace.
        foreach ($imports as $alias => $fqcn) {
            if (strcasecmp(self::sanitizeTypeName($alias), $class) === 0 && class_exists($fqcn)) {
                return $fqcn;
            }
        }

        foreach ($namespaces as $namespace) {
            if ($namespace === '') {
                continue;
            }

            $candidate = $namespace . '\\' . $class;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    protected static function getUseStatementsForClass(ReflectionClass $class): array
    {
        $file = $class->getFileName();

        if (! is_string($file) || ! is_readable($file)) {
            return [];
        }

        $code  = file_get_contents($file);
        $tokens = token_get_all($code);

        $uses             = [];
        $count            = count($tokens);

        // Collect the "use" statements that apply to the class' namespace to support alias resolution later.
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token)) {
                $classTokens = [T_CLASS, T_INTERFACE, T_TRAIT];

                if (defined('T_ENUM')) {
                    $classTokens[] = T_ENUM;
                }

                if (in_array($token[0], $classTokens, true)) {
                    break;
                }
            }

            if (! is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $use = '';

            for ($index++; $index < $count; $index++) {
                $next = $tokens[$index];

                if ($next === ';') {
                    break;
                }

                $use .= is_array($next) ? $next[1] : $next;
            }

            foreach (explode(',', $use) as $clause) {
                $clause = trim($clause);

                if ($clause === '' || str_starts_with($clause, 'function ') || str_starts_with($clause, 'const ')) {
                    continue;
                }

                $alias = null;
                if (stripos($clause, ' as ') !== false) {
                    [$fqcn, $alias] = preg_split('/\s+as\s+/i', $clause);
                    $fqcn           = trim($fqcn);
                    $alias          = trim($alias);
                } else {
                    $fqcn = $clause;
                }

                $fqcn = ltrim($fqcn, '\\');
                $alias ??= self::classBasename($fqcn);
                $uses[$alias] = $fqcn;
            }
        }

        return $uses;
    }

    protected static function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }

    protected static function sanitizeTypeName(string $type): string
    {
        $type = trim($type);

        if (str_ends_with($type, '::class')) {
            $type = substr($type, 0, -7);
        }

        return rtrim($type, "> \t\n\r\0\x0B");
    }
}
