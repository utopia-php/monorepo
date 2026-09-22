<?php

namespace Utopia\Messaging\Adapter\Push;

use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Messages\Push as PushMessage;
use Utopia\Messaging\Priority;
use Utopia\Messaging\Response;

class OneSignal extends PushAdapter
{
    protected const NAME = 'OneSignal';

    /**
     * @param  string  $appId OneSignal application ID
     * @param  string  $restApiKey OneSignal REST API key
     */
    public function __construct(
        private readonly string $appId,
        private readonly string $restApiKey,
    ) {
        parent::__construct();
    }

    /**
     * Get adapter name.
     */
    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * Get max messages per request.
     */
    public function getMaxMessagesPerRequest(): int
    {
        return 2000;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(PushMessage $message): array
    {
        $body = [
            'app_id' => $this->appId,
            'include_subscription_ids' => $message->getTo(),
            'target_channel' => 'push',
        ];

        if (!\is_null($message->getTitle())) {
            $body['headings'] = ['en' => $message->getTitle()];
        }
        if (!\is_null($message->getBody())) {
            $body['contents'] = ['en' => $message->getBody()];
        }
        if (!\is_null($message->getData())) {
            $body['data'] = $message->getData();
        }
        if (!\is_null($message->getAction())) {
            $body['url'] = $message->getAction();
        }
        if (!\is_null($message->getSound())) {
            $body['ios_sound'] = $message->getSound();
            $body['android_sound'] = $message->getSound();
        }
        if (!\is_null($message->getImage())) {
            $body['big_picture'] = $message->getImage();
            $body['chrome_big_picture'] = $message->getImage();
            $body['chrome_web_image'] = $message->getImage();
            $body['ios_attachments'] = ['0' => $message->getImage()];
        }
        if (!\is_null($message->getIcon())) {
            $body['small_icon'] = $message->getIcon();
            $body['chrome_web_icon'] = $message->getIcon();
        }
        if (!\is_null($message->getColor())) {
            $body['android_accent_color'] = 'FF' . ltrim($message->getColor(), '#');
        }
        if (!\is_null($message->getTag())) {
            $body['collapse_id'] = $message->getTag();
        }
        if (!\is_null($message->getBadge())) {
            $body['ios_badgeCount'] = $message->getBadge();
        }
        if (!\is_null($message->getContentAvailable())) {
            $body['content_available'] = $message->getContentAvailable();
        }
        if (!\is_null($message->getCritical())) {
            $body['ios_interruption_level'] = 'critical';
        }
        if (!\is_null($message->getPriority())) {
            $body['priority'] = match ($message->getPriority()) {
                Priority::HIGH => 10,
                Priority::NORMAL => 5,
            };
        }

        $result = $this->request(
            method: 'POST',
            url: 'https://api.onesignal.com/notifications',
            headers: [
                'Content-Type: application/json',
                'Authorization: Key ' . $this->restApiKey,
            ],
            body: $body,
        );

        $response = new Response($this->getType());

        if ($result['statusCode'] === 200) {
            $apiResponse = \is_array($result['response']) ? $result['response'] : [];
            $invalid = $apiResponse['errors']['invalid_subscription_ids'] ?? [];

            if (!\is_array($invalid)) {
                $invalid = [];
            }

            foreach ($message->getTo() as $to) {
                if (\in_array($to, $invalid, true)) {
                    $response->addResult($to, $this->getExpiredErrorMessage());
                } else {
                    $response->incrementDeliveredTo();
                    $response->addResult($to);
                }
            }
        } else {
            $error = $this->getError($result);

            foreach ($message->getTo() as $to) {
                $response->addResult($to, $error);
            }
        }

        return $response->toArray();
    }

    /**
     * @param  array{
     *     statusCode: int,
     *     response: array<string, mixed>|string|null,
     *     error: string|null,
     *     errorCode: int
     * } $result
     */
    protected function getError(array $result): string
    {
        $response = \is_array($result['response']) ? $result['response'] : [];
        $errors = $response['errors'] ?? null;

        if (\is_array($errors) && isset($errors[0]) && \is_string($errors[0]) && $errors[0] !== '') {
            return $errors[0];
        }

        $transportError = $result['error'] ?? null;
        $details = "HTTP status {$result['statusCode']}; cURL error code {$result['errorCode']}";

        return \is_string($transportError) && $transportError !== ''
            ? "{$transportError} ({$details})"
            : "Request failed ({$details})";
    }
}
