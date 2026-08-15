<?php

declare(strict_types=1);

namespace Utopia\Auth\Validator;

use Utopia\Validator;
use Utopia\Validator\WhiteList;

final class EmailWhitelist extends Validator
{
    private WhiteList $emails;

    private WhiteList $domains;

    /**
     * @param array<mixed> $emails
     */
    public function __construct(array $emails)
    {
        $allowedEmails = [];
        $allowedDomains = [];

        foreach ($emails as $email) {
            if (!\is_string($email)) {
                continue;
            }

            $email = trim($email);

            if (!str_contains($email, '*')) {
                $allowedEmails[] = $email;
                continue;
            }

            if (str_starts_with($email, '*@') && substr_count($email, '*') === 1) {
                $allowedDomains[] = substr($email, 2);
            }
        }

        $this->emails = new WhiteList($allowedEmails);
        $this->domains = new WhiteList($allowedDomains);
    }

    public function getDescription(): string
    {
        return 'Email must match an allowed email address or domain';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function isValid($value): bool
    {
        if (!\is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $at = strrpos($value, '@');
        if ($at === false) {
            return false;
        }

        $domain = substr($value, $at + 1);

        return $this->emails->isValid($value) || $this->domains->isValid($domain);
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
