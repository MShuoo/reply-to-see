<?php

namespace Mshuo\ReplyToSee;

use Flarum\Post\Post;
use Flarum\Api\Serializer\BasicPostSerializer;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class HideContentPost
{
    // 序列化回调不一定是单例，请求内缓存只能放静态属性。PHP-FPM 下随请求结束
    private static array $repliedInDiscussion = [];
    private static array $mentionedPostIds = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected ExtensionManager $extensions
    ) {
    }

    
    public function __invoke(BasicPostSerializer $serializer, Post $post, array $attributes): array
    {
        $contentHtml = $attributes['contentHtml'] ?? '';
        if (!is_string($contentHtml) || $contentHtml === '' || !str_contains($contentHtml, '[REPLY]')) {
            return $attributes;
        }
        // 只有楼主的帖子才隐藏。读不到讨论作者时也不隐藏，避免别人手打标签把内容藏起来
        $discussion = $post->discussion;
        $starterId = $discussion ? $discussion->user_id : null;
        if ($starterId === null || (string) $post->user_id !== (string) $starterId) {
            $attributes['contentHtml'] = $this->stripReplyTags($contentHtml);
            return $attributes;
        }
        $replyType = (string) $this->settings->get('mshuo-reply-to-see.reply-type', '0');
        $themeParseType = (string) $this->settings->get('mshuo-reply-to-see.theme-type-parse', '0');

        // 回复主题且仅限首帖：后续楼层不隐藏，去掉标签即可，不再查是否已回复
        if ($replyType === '0' && $themeParseType === '0' && (int) $post->number !== 1) {
            $attributes['contentHtml'] = $this->stripReplyTags($contentHtml);
            return $attributes;
        }

        $actor = $serializer->getActor();
        $postUserId = $post->user_id !== null ? (int) $post->user_id : null;

        // 已经能直接看见的人保留正文，用框标出隐藏区，不露出 [REPLY] 文字
        if ($actor->hasPermission('post.PassReplyToSee') || $this->isAuthor($actor, $postUserId)) {
            $attributes['contentHtml'] = $this->markReplyTags($contentHtml, $this->plainText('mshuo-reply-to-see.forum.reply-visible'));
            return $attributes;
        }

        $placeholder = $this->hiddenHtml(
            $replyType === '1'
                ? 'mshuo-reply-to-see.forum.must-reply-specific'
                : 'mshuo-reply-to-see.forum.must-reply-theme'
        );

        $discussionId = (int) $post->discussion_id;
        if ($actor->isGuest() || $discussionId === 0) {
            $attributes['contentHtml'] = $this->replaceReplyTags($contentHtml, $placeholder);
            return $attributes;
        }

        $actorId = (int) $actor->id;
        $revealed = $replyType === '1'
            ? $this->hasMentionedPost($discussionId, $actorId, (int) $post->id)
            : $this->hasRepliedInDiscussion($discussionId, $actorId);

        $attributes['contentHtml'] = $revealed
            ? $this->stripReplyTags($contentHtml)
            : $this->replaceReplyTags($contentHtml, $placeholder);

        return $attributes;
    }

    private function isAuthor(object $actor, ?int $postUserId): bool
    {
        if (!method_exists($actor, 'isGuest') || $actor->isGuest() || $postUserId === null) {
            return false;
        }

        return (int) $actor->id === $postUserId;
    }

    private function hasRepliedInDiscussion(int $discussionId, int $actorId): bool
    {
        $key = $discussionId . ':' . $actorId;
        if (!array_key_exists($key, self::$repliedInDiscussion)) {
            // 改名、加精等事件帖不算回复；已删除的回复也不再算
            self::$repliedInDiscussion[$key] = Post::query()
                ->where('discussion_id', $discussionId)
                ->where('user_id', $actorId)
                ->where('type', CommentPost::$type)
                ->whereNull('hidden_at')
                ->exists();
        }

        return self::$repliedInDiscussion[$key];
    }

    private function hasMentionedPost(int $discussionId, int $actorId, int $postId): bool
    {
        $key = $discussionId . ':' . $actorId;
        if (!array_key_exists($key, self::$mentionedPostIds)) {
            // 未启用 Mentions 时没有 mentionedBy，调用会让帖子序列化 500。按未回复处理，内容保持隐藏
            self::$mentionedPostIds[$key] = $this->extensions->isEnabled('flarum-mentions')
                ? $this->loadMentionedPostIds($discussionId, $actorId)
                : [];
        }

        return isset(self::$mentionedPostIds[$key][$postId]);
    }

    private function plainText(string $key): string
    {
        return htmlspecialchars(
            (string) $this->translator->trans($key),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private function markReplyTags(string $s, string $labelHtml): string
    {
        static $openTag = '[REPLY]';
        static $closeTag = '[/REPLY]';
        static $openLen = 7;
        static $closeLen = 8;
        $len = strlen($s);
        if ($len === 0 || (strpos($s, $openTag) === false && strpos($s, $closeTag) === false)) {
            return $s;
        }

        $result = '';
        $stack = [];
        $pos = 0;
        while ($pos < $len) {
            $openPos = strpos($s, $openTag, $pos);
            $closePos = strpos($s, $closeTag, $pos);
            if ($openPos === false && $closePos === false) {
                $result .= substr($s, $pos);
                break;
            }
            if ($openPos !== false && ($closePos === false || $openPos < $closePos)) {
                $result .= substr($s, $pos, $openPos - $pos);
                $stack[] = strlen($result);
                $result .= $openTag;
                $pos = $openPos + $openLen;
            } else {
                $result .= substr($s, $pos, $closePos - $pos);
                if (!empty($stack)) {
                    $openStart = array_pop($stack);
                    $inner = substr($result, $openStart + $openLen);
                    $result = substr($result, 0, $openStart) . $this->wrapMarked($inner, $labelHtml);
                }
                $pos = $closePos + $closeLen;
            }
        }

        // 未闭合时从内向外包，开始标签文字去掉，结果里不再留下 [REPLY]
        while (!empty($stack)) {
            $openStart = array_pop($stack);
            $inner = substr($result, $openStart + $openLen);
            $result = substr($result, 0, $openStart) . $this->wrapMarked($inner, $labelHtml);
        }

        return $result;
    }

    private function wrapMarked(string $inner, string $labelHtml): string
    {
        return '<div class="ReplyToSee-Marked">'
            . '<div class="ReplyToSee-Marked-label">' . $labelHtml . '</div>'
            . '<div class="ReplyToSee-Marked-body">' . $inner . '</div>'
            . '</div>';
    }
    /**
     * 一次查出该用户在本讨论里提及过的帖子。跨讨论提及不算回复。
     *
     * @return array<int, true>
     */
    private function loadMentionedPostIds(int $discussionId, int $actorId): array
    {
        // 不能用 DB 门面，Flarum 不初始化 Laravel facade root，请求内调用会直接抛异常
        $ids = Post::query()
            ->join('post_mentions_post', 'posts.id', '=', 'post_mentions_post.post_id')
            ->where('posts.discussion_id', $discussionId)
            ->where('posts.user_id', $actorId)
            ->where('posts.type', CommentPost::$type)
            ->whereNull('posts.hidden_at')
            ->pluck('post_mentions_post.mentions_post_id');

        $set = [];
        foreach ($ids as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }

    private function hiddenHtml(string $key): string
    {
        $text = htmlspecialchars(
            (string) $this->translator->trans($key),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return '<div class="ReplyToSee-Hidden">' . $text . '</div>';
    }
   

    private function replaceReplyTags(string $s, string $replace): string
    {
        static $openTag = '[REPLY]';
        static $closeTag = '[/REPLY]';
        static $openLen = 7;
        static $closeLen = 8;
        $len = strlen($s);
        if ($len === 0 || (strpos($s, $openTag) === false && strpos($s, $closeTag) === false)) {
            return $s;
        }
        $result = '';
        $stack = [];
        $pos = 0;
        while ($pos < $len) {
            $openPos = strpos($s, $openTag, $pos);
            $closePos = strpos($s, $closeTag, $pos);
            if ($openPos === false && $closePos === false) {
                $result .= substr($s, $pos);
                break;
            }
            if ($openPos !== false && ($closePos === false || $openPos < $closePos)) {
                $result .= substr($s, $pos, $openPos - $pos);
                $stack[] = strlen($result);
                $result .= $openTag;
                $pos = $openPos + $openLen;
            } else {
                $result .= substr($s, $pos, $closePos - $pos);
                if (!empty($stack)) {
                    $lastPos = array_pop($stack);
                    $result = substr($result, 0, $lastPos) . $replace;
                }
                $pos = $closePos + $closeLen;
            }
        }
        // 未闭合时从最早的开始标签截到文末，避免后面的正文漏出
        if (!empty($stack)) {
            $result = substr($result, 0, $stack[0]) . $replace;
        }
        return $result;
    }
    private function stripReplyTags(string $s): string
    {
        static $openTag = '[REPLY]';
        static $closeTag = '[/REPLY]';
        static $openLen = 7;
        static $closeLen = 8;
        $len = strlen($s);
        if ($len === 0 || (strpos($s, $openTag) === false && strpos($s, $closeTag) === false)) {
            return $s;
        }
        $result = '';
        $stack = [];
        $pos = 0;
        while ($pos < $len) {
            $openPos = strpos($s, $openTag, $pos);
            $closePos = strpos($s, $closeTag, $pos);
            if ($openPos === false && $closePos === false) {
                $result .= substr($s, $pos);
                break;
            }
            if ($openPos !== false && ($closePos === false || $openPos < $closePos)) {
                $result .= substr($s, $pos, $openPos - $pos);
                $stack[] = strlen($result);
                $result .= $openTag;
                $pos = $openPos + $openLen;
            } else {
                $result .= substr($s, $pos, $closePos - $pos);
                if (!empty($stack)) {
                    $openStart = array_pop($stack);
                    $result = substr($result, 0, $openStart) . substr($result, $openStart + $openLen);
                }
                $pos = $closePos + $closeLen;
            }
        }
        // 从后往前删落单的开始标签，前面的位置才不会被后面的删除弄偏
        while (!empty($stack)) {
            $openStart = array_pop($stack);
            $result = substr($result, 0, $openStart) . substr($result, $openStart + $openLen);
        }
        return $result;
    }

}
