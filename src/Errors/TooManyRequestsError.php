<?php

namespace Xolvio\OpenApiGenerator\Errors;

use Xolvio\OpenApiGenerator\Data\Error;
use Xolvio\OpenApiGenerator\Attributes\HttpResponseStatus;

#[HttpResponseStatus(status: 429)]
class TooManyRequestsError extends Error {}
