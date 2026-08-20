<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

class Exception extends \Exception
{
    protected string $response = '';
    protected string $error = '';
    protected string $errorDescription = '';

    public function __construct(string $response = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->response = $response;
        $this->message = $response;
        $decoded = json_decode($response, true);
        if (\is_array($decoded)) {
            if (\is_array($decoded['error'] ?? '')) {
                $this->error = $decoded['error']['status'] ?? 'Unknown error';
                $this->errorDescription = $decoded['error']['message'] ?? 'No description';
            } elseif (\is_array($decoded['errors'] ?? '')) {
                $this->error = $decoded['error'] ?? $decoded['message'] ?? 'Unknown error';
                $this->errorDescription = $decoded['errors'][0]['message'] ?? 'No description';
            } else {
                $this->error = $decoded['error'] ?? $decoded['message'] ?? 'Unknown error';
                $this->errorDescription = $decoded['error_description'] ?? 'No description';
            }

            $this->message = $this->error . ': ' . $this->errorDescription;
        }

        parent::__construct($this->message, $code, $previous);
    }

    public function getResponse(): string
    {
        return $this->response;
    }

    /**
     * Get the error parameter from the response.
     *
     * See https://datatracker.ietf.org/doc/html/rfc6749#section-5.2 for more information.
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Get the error_description parameter from the response.
     *
     * See https://datatracker.ietf.org/doc/html/rfc6749#section-5.2 for more information.
     */
    public function getErrorDescription(): string
    {
        return $this->errorDescription;
    }
}
