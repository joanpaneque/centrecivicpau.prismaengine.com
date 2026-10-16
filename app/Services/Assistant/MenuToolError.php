<?php

namespace App\Services\Assistant;

use RuntimeException;

/**
 * A tool call that cannot be done as asked; the message goes back to the model.
 */
class MenuToolError extends RuntimeException {}
