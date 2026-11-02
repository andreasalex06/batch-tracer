<?php
namespace App\Services;
final class WorkflowException extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
