<?php

namespace Mshuo\ReplyToSee\Listener;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Mail\Events\MessageSending;
use Mshuo\ReplyToSee\ReplyTagParser;
use Symfony\Contracts\Translation\TranslatorInterface;

class HideContentInMail
{
    protected $settings;
    protected $translator;

    public function __construct(SettingsRepositoryInterface $settings, TranslatorInterface $translator)
    {
        $this->settings = $settings;
        $this->translator = $translator;
    }

    public function __invoke(MessageSending $event): void
    {
        $message = $event->message ?? null;
        if (!is_object($message)) {
            return;
        }

        // 邮件会留在邮箱里，发送时也对应不到收件人是否已回复，因此一律换成占位
        $replyType = (string) $this->settings->get('mshuo-reply-to-see.reply-type', '0');
        $key = $replyType === '1'
            ? 'mshuo-reply-to-see.forum.must-reply-specific'
            : 'mshuo-reply-to-see.forum.must-reply-theme';
        $placeholder = (string) $this->translator->trans($key);

        if (method_exists($message, 'getTextBody') || method_exists($message, 'getHtmlBody')) {
            $this->rewriteSymfonyEmail($message, $placeholder);
            return;
        }

        $this->rewriteMimeEntity($message, $placeholder);
    }

    private function rewriteSymfonyEmail(object $message, string $placeholder): void
    {
        if (method_exists($message, 'getTextBody') && method_exists($message, 'text')) {
            $text = $message->getTextBody();
            if (is_string($text)) {
                $message->text(ReplyTagParser::replace($text, $placeholder));
            }
        }

        if (method_exists($message, 'getHtmlBody') && method_exists($message, 'html')) {
            $html = $message->getHtmlBody();
            if (is_string($html)) {
                $message->html(ReplyTagParser::replace($html, $this->escape($placeholder)));
            }
        }
    }

    private function rewriteMimeEntity(object $entity, string $placeholder): void
    {
        if ($this->isTextPart($entity) && method_exists($entity, 'getBody') && method_exists($entity, 'setBody')) {
            $body = $entity->getBody();
            if (is_string($body) && $body !== '') {
                $token = $this->isHtmlPart($entity) ? $this->escape($placeholder) : $placeholder;
                $rewritten = ReplyTagParser::replace($body, $token);
                if ($rewritten !== $body) {
                    $entity->setBody($rewritten);
                }
            }
        }

        if (!method_exists($entity, 'getChildren')) {
            return;
        }

        foreach ($entity->getChildren() as $child) {
            if (is_object($child)) {
                $this->rewriteMimeEntity($child, $placeholder);
            }
        }
    }

    private function isTextPart(object $entity): bool
    {
        if (!method_exists($entity, 'getContentType')) {
            return false;
        }

        $type = strtolower((string) $entity->getContentType());

        return strpos($type, 'text/plain') === 0
            || strpos($type, 'text/html') === 0
            || $type === 'text';
    }

    private function isHtmlPart(object $entity): bool
    {
        if (!method_exists($entity, 'getContentType')) {
            return false;
        }

        return strpos(strtolower((string) $entity->getContentType()), 'text/html') === 0;
    }

    private function escape(string $placeholder): string
    {
        return htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
