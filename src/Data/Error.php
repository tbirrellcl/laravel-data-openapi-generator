<?php

namespace Xolvio\OpenApiGenerator\Data;

use Illuminate\Http\JsonResponse;
use ReflectionClass;
use Spatie\LaravelData\Data;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Xolvio\OpenApiGenerator\Attributes\HttpResponseStatus;

class Error extends Data
{
    public function __construct(
        public string $error,
        public ?string $details = null,
    ) {
        if ($this->details === null) {
            $this->details = $this->error;
        }
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(
            array_filter(
                [
                    'error'   => $this->error,
                    'details' => $this->details,
                ],
                static fn ($value) => null !== $value,
            ),
            $this->resolveStatusCode(),
        );
    }

    protected function resolveStatusCode(): int
    {
        $reflection = new ReflectionClass(static::class);
        $attributes = $reflection->getAttributes(HttpResponseStatus::class);

        if (count($attributes) > 0) {
            $arguments = $attributes[0]->getArguments();

            if (isset($arguments['status'])) {
                return (int) $arguments['status'];
            }

            if (isset($arguments[0])) {
                return (int) $arguments[0];
            }
        }

        return HttpResponse::HTTP_INTERNAL_SERVER_ERROR;
    }
}
