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
        static $closeLen =8;
        if (strpos($s, $openTag) === false) return $s;
        if (strpos($s, $closeTag) === false) return $s;
        $result = '';
        $stack = [];
        $pos = 0;
        $len = strlen($s);
        if ($len === 0) return $s;
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
                $pos = $openPos + $openLen;
            } else {
                $result .= substr($s, $pos, $closePos - $pos);
                if (!empty($stack)) {
                    $lastPos = array_pop($stack);
                    $result = substr($result, 0, $lastPos) . $replace;
                } else {
                    $result .= $closeTag;
                }
                $pos = $closePos + $closeLen;
            }
        }
        if (!empty($stack)) {
            $fixedResult = '';
            $lastPos = 0;
            foreach ($stack as $openTagStart) {
                $fixedResult .= substr($result, $lastPos, $openTagStart - $lastPos) . $openTag;
                $lastPos = $openTagStart;
            }
            $fixedResult .= substr($result, $lastPos);
            return $fixedResult;
        }
        return $result;
    }
    private function stripReplyTags(string $s): string
    {
        static $openTag  = '[REPLY]';
        static $closeTag = '[/REPLY]';
        static $openLen  = 7;
        static $closeLen = 8;
        if (strpos($s, $openTag) === false) return $s;
        if (strpos($s, $closeTag) === false) return $s;
        $result = '';
        $stack  = [];
        $pos    = 0;
        $len    = strlen($s);
        if ($len === 0) return $s;
        while ($pos < $len) {
            $openPos  = strpos($s, $openTag, $pos);
            $closePos = strpos($s, $closeTag, $pos);
            if ($openPos === false && $closePos === false) {
                $result .= substr($s, $pos);
                break;
            }
            if ($openPos !== false && ($closePos === false || $openPos < $closePos)) {
                $result .= substr($s, $pos, $openPos - $pos);
                $stack[] = strlen($result);
                $pos = $openPos + $openLen;
            } else {
                $result .= substr($s, $pos, $closePos - $pos);
                if (!empty($stack)) {
                    array_pop($stack);
                } else {
                    $result .= $closeTag;
                }
                $pos = $closePos + $closeLen;
            }
        }
        if (!empty($stack)) {
            $fixedResult = '';
            $lastPos = 0;
            foreach ($stack as $openTagStart) {
                $fixedResult .= substr($result, $lastPos, $openTagStart - $lastPos) . $openTag;
                $lastPos = $openTagStart;
            }
            $fixedResult .= substr($result, $lastPos);
            return $fixedResult;
        }
        return $result;
    }
     /*
    function replaceReplyTags_o(string $s, string $replace): string
    {
        $openTag  = '[REPLY]';
        $closeTag = '[/REPLY]';
        $openLen  = strlen($openTag);
        $closeLen = strlen($closeTag);
        $len = strlen($s);
        if ($len === 0) return $s;
        $result = '';
        $stack = [];
        $i = 0;
        while ($i < $len) {
            if ($i + $openLen <= $len && substr($s, $i, $openLen) === $openTag) {
                $stack[] = strlen($result);
                $i += $openLen;
                continue;
            }
            if ($i + $closeLen <= $len && substr($s, $i, $closeLen) === $closeTag) {
                if (!empty($stack)) {
                    $pos = array_pop($stack);
                    $result = substr($result, 0, $pos) . $replace;
                } else {
                    $result .= $closeTag;
                }
                $i += $closeLen;
                continue;
            }
            $result .= $s[$i];
            $i++;
        }
        if (!empty($stack)) {
            $fixed = '';
            $lastPos = 0;
            foreach ($stack as $pos) {
                $fixed .= substr($result, $lastPos, $pos - $lastPos) . $openTag;
                $lastPos = $pos;
            }
            $fixed .= substr($result, $lastPos);
            return $fixed;
        }
        return $result;
    }
    private function stripReplyTags_o(string $contentHtml): string
    {
        static $openTag = '[REPLY]';
        static $closeTag = '[/REPLY]';
        static $openLen = 7;
        static $closeLen = 8;
        $len = strlen($contentHtml);
        if ($len === 0) return $contentHtml;
        if (strpos($contentHtml, $openTag) === false) return $contentHtml;
        $result = '';
        $stack = [];
        $i = 0;
        while ($i < $len) {
            if ($i + $openLen <= $len && substr($contentHtml, $i, $openLen) === $openTag) {
                $stack[] = strlen($result);
                $i += $openLen;
                continue;
            }
            if ($i + $closeLen <= $len && substr($contentHtml, $i, $closeLen) === $closeTag) {
                if (!empty($stack)) {
                    array_pop($stack);
                } else {
                    $result .= $closeTag;
                }
                $i += $closeLen;
                continue;
            }
            $result .= $contentHtml[$i];
            $i++;
        }
        if (!empty($stack)) {
            $fixed = '';
            $lastPos = 0;
            foreach ($stack as $pos) {
                $fixed .= substr($result, $lastPos, $pos - $lastPos) . $openTag;
                $lastPos = $pos;
            }
            $fixed .= substr($result, $lastPos);
            return $fixed;
        }
        return $result;
    }
    */
}
