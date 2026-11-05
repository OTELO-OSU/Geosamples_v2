<?php

namespace App\Exception;

/**
 * Application error returned to the client as JSON {error, details?}.
 */
class ApiException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 400, private array $details = [])
    {
        parent::__construct($message, $status);
    }

    public function getStatus(): int { return $this->status; }
    public function getDetails(): array { return $this->details; }

    public static function notFound(string $what = 'Resource not found'): self { return new self($what, 404); }
    public static function forbidden(string $msg = 'Access denied'): self { return new self($msg, 403); }
    public static function unauthorized(string $msg = 'Authentication required'): self { return new self($msg, 401); }
    public static function validation(array $details, string $msg = 'Invalid data'): self { return new self($msg, 422, $details); }
}
