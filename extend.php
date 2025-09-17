<?php

namespace Mshuo\ReplyToSee;

use Flarum\Extend;
use Flarum\User\User;
use Flarum\Api\Serializer\PostSerializer;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),
        
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    (new Extend\Settings())
        ->serializeToForum('mshuo-reply-to-see.reply-type', 'mshuo-reply-to-see.reply-type'),
        
    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\ApiSerializer(PostSerializer::class))
        ->attributes(HideContentPost::class),
];