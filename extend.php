<?php

namespace Mshuo\ReplyToSee;

use Flarum\Api\Serializer\BasicPostSerializer;
use Flarum\Extend;
use Flarum\User\User;

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

    // 首帖、末帖走 BasicPostSerializer。只挂子类时，创建讨论的响应会把已去掉的标签盖回去
    (new Extend\ApiSerializer(BasicPostSerializer::class))
        ->attributes(HideContentPost::class),
];