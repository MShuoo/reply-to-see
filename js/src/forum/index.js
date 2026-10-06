import { extend } from 'flarum/extend';
import app from 'flarum/forum/app';
import TextEditor from 'flarum/common/components/TextEditor';
import TextEditorButton from 'flarum/common/components/TextEditorButton';
import Post from 'flarum/common/models/Post';
import refreshHiddenPosts, { discussionIdOf } from './refreshHiddenPosts';

app.initializers.add('mshuo-reply-to-see', () => {
    // 序列化结果缓存在 store 里，回复成功后必须重拉，隐藏块才会换成正文
    extend(Post.prototype, 'save', function (promise) {
        if (this.exists || !app.session.user) return;

        const knownDiscussionId = discussionIdOf(this);

        promise.then((saved) => {
            const discussionId = discussionIdOf(saved) || knownDiscussionId;
            if (!discussionId) return;

            return refreshHiddenPosts(discussionId, saved);
        }).catch(() => {});
    });

    extend(TextEditor.prototype, 'toolbarItems', function (items) {
        const replyType = app.forum.attribute('mshuo-reply-to-see.reply-type') === '1';
        const discussion = this.attrs.composer?.body?.attrs?.discussion ? 0 : 1;
        if (replyType || discussion) {
            items.add('insert-reply-to-see',
                TextEditorButton.component({
                    icon: 'fas fa-comment-slash',
                    title: app.translator.trans('mshuo-reply-to-see.forum.insert-reply-to-see'),
                    onclick: () => {
                        const editor = this.attrs.composer.editor;
                        if (!editor) return;
                        const [start, end] = editor.getSelectionRange();
                        const openTag = "[REPLY]";
                        const closeTag = "[/REPLY]";
                        if (start !== end) {
                            const selected = editor.el.value.slice(start, end);
                            editor.insertBetween(start, end, openTag + selected + closeTag, false);
                            editor.moveCursorTo(start + openTag.length + selected.length + closeTag.length);
                        } else {
                            editor.insertAtCursor(openTag + closeTag, false);
                            editor.moveCursorTo(start + openTag.length);
                        }
                    }
                })
            );
        } 
    });
});