import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';

const HIDDEN_MARK = 'ReplyToSee-Hidden';
// 与核心每页帖子数对齐，避免默认分页把后半截丢掉
const CHUNK_SIZE = 20;

export function discussionIdOf(post) {
  const data = post && post.data && post.data.relationships && post.data.relationships.discussion
    ? post.data.relationships.discussion.data
    : null;

  if (data && !Array.isArray(data) && data.id != null) {
    return String(data.id);
  }

  return null;
}

function isHiddenPost(post) {
  const html = post && typeof post.contentHtml === 'function' ? post.contentHtml() : '';
  return typeof html === 'string' && html.indexOf(HIDDEN_MARK) !== -1;
}

function listedInCurrentDiscussion(post, discussionId) {
  const page = app.current;
  if (!page || typeof page.get !== 'function') return false;

  const discussion = page.get('discussion');
  if (!discussion || String(discussion.id()) !== String(discussionId)) return false;
  if (typeof discussion.postIds !== 'function') return false;

  const id = post.id();
  return discussion.postIds().some((postId) => String(postId) === String(id));
}

function hiddenPostIds(discussionId, savedPostId) {
  return app.store.all('posts')
    .filter((post) => {
      if (!post || String(post.id()) === String(savedPostId)) return false;
      const sameDiscussion = discussionIdOf(post) === String(discussionId)
        || listedInCurrentDiscussion(post, discussionId);
      return sameDiscussion && isHiddenPost(post);
    })
    .map((post) => post.id());
}

function chunk(ids) {
  const groups = [];

  for (let i = 0; i < ids.length; i += CHUNK_SIZE) {
    groups.push(ids.slice(i, i + CHUNK_SIZE));
  }

  return groups;
}

function scrollToSavedPost(discussionId, savedPost) {
  const page = app.current;
  if (!page || typeof page.get !== 'function') return;

  const discussion = page.get('discussion');
  if (!discussion || String(discussion.id()) !== String(discussionId)) return;

  const stream = page.get('stream');
  const number = savedPost && typeof savedPost.number === 'function' ? savedPost.number() : null;
  if (!stream || typeof stream.goToNumber !== 'function' || number == null) return;

  // 新回复还没进可见流时，交给核心自己滚，避免 goToNumber 重置整页
  const visible = typeof stream.posts === 'function'
    ? stream.posts().some((post) => post && Number(post.number()) === Number(number))
    : false;
  if (!visible) return;

  stream.goToNumber(number, true);
}

function redraw() {
  if (typeof m.redraw.sync === 'function') {
    m.redraw.sync();
  } else {
    m.redraw();
  }
}

function showReloadAlert() {
  if (!app.alerts || typeof app.alerts.show !== 'function') return;

  const reload = Button.component({
    className: 'Button Button--link',
    onclick: () => window.location.reload(),
  }, app.translator.trans('mshuo-reply-to-see.forum.reload'));

  app.alerts.show(
    { type: 'error', controls: [reload] },
    app.translator.trans('mshuo-reply-to-see.forum.refresh-failed')
  );
}

export default function refreshHiddenPosts(discussionId, savedPost) {
  const ids = hiddenPostIds(discussionId, savedPost && savedPost.id && savedPost.id());
  if (!ids.length) return Promise.resolve();

  return Promise.all(chunk(ids).map((group) => {
    return app.store.find('posts', group, { page: { limit: group.length } })
      .then(() => true)
      .catch(() => false);
  })).then((results) => {
    redraw();
    scrollToSavedPost(discussionId, savedPost);

    if (results.some((ok) => !ok)) {
      showReloadAlert();
    }
  }).catch(() => {
    showReloadAlert();
  });
}
