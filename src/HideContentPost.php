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

    /*
    private function replaceReplyTags_o(string $contentHtml, string $replaceHtml): string
    {
        static $openTag = '[REPLY]';
        static $closeTag = '[/REPLY]';
        static $openLen = 7;
        static $closeLen = 8;
        $len = strlen($contentHtml);
        if ($len === 0) return $contentHtml;
        $result = [];
        $i = 0;
        while ($i < $len) {
            $posOpen = strpos($contentHtml, $openTag, $i);
            if ($posOpen === false) {
                $result[] = substr($contentHtml, $i);
                break;
            }
            if ($posOpen > $i) {
                $result[] = substr($contentHtml, $i, $posOpen - $i);
            }
            $depth = 1;
            $start = $posOpen + $openLen;
            $j = $start;
            while ($j < $len && $depth > 0) {
                if ($j + $openLen <= $len && substr_compare($contentHtml, $openTag, $j, $openLen) === 0) {
                    $depth++;
                    $j += $openLen;
                } elseif ($j + $closeLen <= $len && substr_compare($contentHtml, $closeTag, $j, $closeLen) === 0) {
                    $depth--;
                    if ($depth === 0) {
                        $result[] = $replaceHtml;
                        $i = $j + $closeLen;
                        break;
                    }
                    $j += $closeLen;
                } else {
                    $j++;
                }
            }
            if ($depth > 0) {
                $result[] = substr($contentHtml, $posOpen);
                break;
            }
        }

        return implode('', $result);
    }
    */
    private function replaceReplyTags(string $s, string $replace): string
    {
        static $open  = '[REPLY]';
        static $close = '[/REPLY]';
        static $oLen  = 7;
        static $cLen  = 8;
        $len = strlen($s);
        if ($len === 0) return $s;
        if (strpos($s, $open) === false) return $s;
        $parts = [];
        $i = 0;
        while ($i < $len) {
            $pOpen = strpos($s, $open, $i);
            if ($pOpen === false) {
                $parts[] = substr($s, $i);
                break;
            }
            if ($pOpen > $i) {
                $parts[] = substr($s, $i, $pOpen - $i);
            }
            $depth = 1;
            $j = $pOpen + $oLen;
            while ($j < $len && $depth > 0) {
                $nextOpen  = strpos($s, $open,  $j);
                $nextClose = strpos($s, $close, $j);
                if ($nextClose === false) {
                    $parts[] = substr($s, $pOpen);
                    $i = $len;
                    break 2;
                }
                if ($nextOpen === false || $nextClose < $nextOpen) {
                    $depth--;
                    $j = $nextClose + $cLen;
                    if ($depth === 0) {
                        $parts[] = $replace;
                        $i = $j;
                    }
                } else {
                    $depth++;
                    $j = $nextOpen + $oLen;
                }
            }
            if ($depth > 0) {
                $parts[] = substr($s, $pOpen);
                break;
            }
        }
        return implode('', $parts);
    }
    private function stripReplyTags(string $contentHtml): string
    {
        static $openTag = '[REPLY]';
        static $closeTag = '[/REPLY]';
        static $openLen = 7;
        static $closeLen = 8;
        $len = strlen($contentHtml);
        if ($len === 0) return $contentHtml;
        if (strpos($contentHtml, $openTag) === false) return $contentHtml;
        $result = [];
        $i = 0;
        $inTag = false;
        while ($i < $len) {
            if (!$inTag) {
                $posOpen = strpos($contentHtml, $openTag, $i);
                if ($posOpen === false) {
                    $result[] = substr($contentHtml, $i);
                    break;
                }
                if ($posOpen > $i) {
                    $result[] = substr($contentHtml, $i, $posOpen - $i);
                }
                $inTag = true;
                $depth = 1;
                $i = $posOpen + $openLen;
            } else {
                $posOpen = strpos($contentHtml, $openTag, $i);
                $posClose = strpos($contentHtml, $closeTag, $i);
                if ($posOpen === false && $posClose === false) {
                    $result[] = substr($contentHtml, $i);
                    break;
                }
                if ($posOpen === false) $posOpen = $len + 1;
                if ($posClose === false) $posClose = $len + 1;
                if ($posOpen < $posClose) {
                    $result[] = substr($contentHtml, $i, $posOpen - $i);
                    $depth++;
                    $i = $posOpen + $openLen;
                } else {
                    $result[] = substr($contentHtml, $i, $posClose - $i);
                    $depth--;
                    $i = $posClose + $closeLen;
                    if ($depth === 0) {
                        $inTag = false;
                    }
                }
            }
        }
        return implode('', $result);
    }
    
}
