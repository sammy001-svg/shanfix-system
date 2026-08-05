<?php
namespace App\Core;

/**
 * Thrown to abort a request with a specific HTTP status.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        private int $status = 500,
        string $message = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'You need to sign in to continue.',
            403 => 'You do not have permission to do that.',
            404 => 'The page you are looking for was not found.',
            405 => 'Method not allowed.',
            419 => 'Your session expired. Please try again.',
            422 => 'The information supplied could not be processed.',
            429 => 'Too many attempts. Please wait and try again.',
            default => 'Something went wrong on our side.',
        };
    }
}
