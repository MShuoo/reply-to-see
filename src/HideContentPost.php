<?php
namespace Mshuo\ReplyToSee;

use Flarum\Post\Post;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class HideContentPost
{
    protected $settings;
    protected $translator;

    private string $mustReplyHtml = '';
    public function __construct(SettingsRepositoryInterface $settings, TranslatorInterface $translator)
    {
        $this->settings = $settings;
        $this->translator = $translator;
    }

    
    public function __invoke(PostSerializer $serializer, Post $post, array $attributes): array
    {
        $contentHtml = $attributes['contentHtml'] ?? '';
        if (empty($contentHtml) || !str_contains($contentHtml, '[REPLY]')) {
            return $attributes;
        }
        $themeParseType = $this->settings->get('mshuo-reply-to-see.theme-type-parse', '0');
        $replyType = $this->settings->get('mshuo-reply-to-see.reply-type', '0');

        $actor = $serializer->getActor();
        if ((string) $replyType === '0' && $themeParseType === '0' && $post->number !== 1 ) {
            return $attributes;
        }
       
        if ((string) $replyType === '0' && (string) $themeParseType === '1' && $post->number !== 1) {
            $attributes['contentHtml'] = $this->stripReplyTags($contentHtml);
            return $attributes;
        }

        if ($actor->hasPermission('post.PassReplyToSee') || $actor->id === $post->user_id) {
            $attributes['contentHtml'] = $this->stripReplyTags($contentHtml);
            return $attributes;
        }

        
        $this->mustReplyHtml = ((string) $replyType === '0') ? $this->getMustReplyThemeHtml() : $this->getMustReplySpecificHtml();

        $discussion = $post->discussion ?? null;
        if ($actor->isGuest() || !$discussion ) {
            $attributes['contentHtml'] = $this->replaceReplyTags($contentHtml, $this->getMustReplyHtml());
            return $attributes;
        }


        $reply_exists =false;
        if($replyType === '1'){
            $reply_exists = $post->mentionedBy()->where('user_id', $actor->id)->exists();
        }else{
            $reply_exists = $discussion->posts()->where('user_id', $actor->id)->whereNull('hidden_at')->exists();
        }
        $attributes['contentHtml'] = $reply_exists
            ? $this->stripReplyTags($contentHtml)
            : $this->replaceReplyTags($contentHtml, $this->getMustReplyHtml());
        return $attributes;
    }

    

    private function getMustReplyHtml(): string
    {
        return $this->mustReplyHtml;
    }
    private function getMustReplySpecificHtml(): string
    {
        return '<div class="ReplyToSee-Hidden">'.$this->translator->trans('mshuo-reply-to-see.forum.must-reply-specific').'</div>';
    }

    private function getMustReplyThemeHtml(): string
    {
        return '<div class="ReplyToSee-Hidden">'.$this->translator->trans('mshuo-reply-to-see.forum.must-reply-theme').'</div>';
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
