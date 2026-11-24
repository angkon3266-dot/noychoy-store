<?php

namespace App\Mcp;

/**
 * A mistake Claude can fix itself — an unknown product, a missing confirm
 * flag. Reported back as a tool error (isError: true) with this message, which
 * should always say what to do next, never as a stack trace.
 */
class ToolError extends \RuntimeException {}
