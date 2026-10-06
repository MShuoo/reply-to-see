import { extend } from 'flarum/extend';
import app from 'flarum/forum/app';
import TextEditor from 'flarum/common/components/TextEditor';
import TextEditorButton from 'flarum/common/components/TextEditorButton';

function userIdOf(model) {
    if (!model) return null;
    if (typeof model.user === 'function') {
        const user = model.user();
        if (user && typeof user.id === 'function' && user.id() != null) {
            return String(user.id());
        }
    }
    const data = model.data && model.data.relationships && model.data.relationships.user
        ? model.data.relationships.user.data
        : null;
    if (data && !Array.isArray(data) && data.id != null) {
        return String(data.id);
    }
    return null;
}

function canInsertReplyTag(editor) {
    const actor = app.session.user;
    if (!actor || typeof actor.id !== 'function') return false;

    const attrs = editor.attrs && editor.attrs.composer && editor.attrs.composer.body
        ? editor.attrs.composer.body.attrs
        : null;
    if (!attrs) return false;

    let discussion = attrs.discussion || null;
    if (!discussion && attrs.post && typeof attrs.post.discussion === 'function') {
        discussion = attrs.post.discussion() || null;
    }
    // 发新主题时还没有讨论，发帖人就是将来的楼主
    if (!discussion) return !attrs.post;

    const starterId = userIdOf(discussion);
    return starterId != null && starterId === String(actor.id());
}

app.initializers.add('mshuo-reply-to-see', () => {
    extend(TextEditor.prototype, 'toolbarItems', function (items) {
        if (canInsertReplyTag(this)) {
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