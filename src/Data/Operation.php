<?php

namespace Xolvio\OpenApiGenerator\Data;

use Closure;
use Exception;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionFunction;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class Operation extends Data
{
    public function __construct(
        public ?string $description,
        /** @var null|DataCollection<int,Parameter> */
        #[DataCollectionOf(Parameter::class)]
        public ?DataCollection $parameters,
        public ?RequestBody $requestBody,
        /** @var DataCollection<string,Response> */
        #[DataCollectionOf(Response::class)]
        public DataCollection $responses,
        /** @var null|DataCollection<int,SecurityScheme> */
        #[DataCollectionOf(SecurityScheme::class)]
        public ?DataCollection $security,
    ) {}

    public static function fromRoute(Route $route): self
    {
        $uses = $route->action['uses'];

        if (is_string($uses)) {
            $controller_function = (new ReflectionClass($route->getController()))
                ->getMethod($route->getActionMethod());
        } elseif ($uses instanceof Closure) {
            $controller_function = new ReflectionFunction($uses);
        } else {
            throw new Exception('Unknown route uses');
        }

        $responses = [
            HttpResponse::HTTP_OK => Response::fromRoute($controller_function),
        ];

        $security = SecurityScheme::fromRoute($route);

        if ($security) {
            $responses[HttpResponse::HTTP_UNAUTHORIZED] = Response::unauthorized($controller_function);
        }

        $permissions = SecurityScheme::getPermissions($route);

        $description = null;

        if (count($permissions) > 0) {
            $permissions_string = implode(', ', $permissions);

            $description = "Permissions needed: {$permissions_string}";

            $responses[HttpResponse::HTTP_FORBIDDEN] = Response::forbidden($controller_function);
        }

        return self::from([
            'description' => $description,
            'parameters'  => Parameter::fromRoute($route, $controller_function),
            'requestBody' => RequestBody::fromRoute($controller_function),
            'responses'   => $responses,
            'security'    => $security,
        ]);
    }

    /**
     * @return array<int|string,mixed>
     */
    public function transform(
        null|TransformationContext|TransformationContextFactory $transformationContext = null,
    ): array {
        return array_filter(
            parent::transform($transformationContext),
            fn (mixed $value) => null !== $value,
        );
    }
}
