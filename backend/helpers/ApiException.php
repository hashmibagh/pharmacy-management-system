<?php
declare(strict_types=1);

namespace Pharmacy\Helpers;

/**
 * Exception that maps directly to a client-facing API error response.
 * Throw these from middleware / controllers / services instead of
 * calling Response::error() mid-stack.
 */
class ApiException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private array $errors;

    public function __construct(string $message = 'Error', int $status = 400, array $errors = [])
    {
        parent::__construct($message, $status);
        $this->errors = $errors;
    }

    public function getStatus(): int
    {
        return $this->getCode() >= 100 ? $this->getCode() : 400;
    }

    /** @return array<string, mixed> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
