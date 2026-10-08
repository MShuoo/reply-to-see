import { extend } from 'flarum/extend';
import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import extractText from 'flarum/common/utils/extractText';

app.initializers.add('mshuo-reply-to-see', () => {
    // 扩展页标题下的简介来自 composer.json，这里换成语言包
    extend(ExtensionPage.prototype, 'oninit', function () {
        if (!this.extension || this.extension.id !== 'mshuo-reply-to-see') return;
        this.extension.description = extractText(app.translator.trans('mshuo-reply-to-see.admin.description'));
    });

    let replySelectEl;
    let themeSelectEl;
    const toggleTheme = () => {
      if (replySelectEl && themeSelectEl) {
        themeSelectEl.style.display = replySelectEl.value === '1' ? 'none' : '';
      }
    };

    app.extensionData.for('mshuo-reply-to-see')
      .registerSetting({
        setting: 'mshuo-reply-to-see.reply-type',
        label: app.translator.trans('mshuo-reply-to-see.admin.settings.reply-type-lable'),
        class: 'ms-reply-type',
        type: 'select',
        help: app.translator.trans('mshuo-reply-to-see.admin.settings.reply-type-lable-helpText'),
        options: {
          '0': app.translator.trans('mshuo-reply-to-see.admin.reply-type.0'),
          '1': app.translator.trans('mshuo-reply-to-see.admin.reply-type.1'),
        },
        default: '0',
        oncreate: vnode => {
          replySelectEl = vnode.dom;
          replySelectEl.addEventListener('change', toggleTheme);
          toggleTheme();
        },
        onremove: () => {
          if(replySelectEl) {
            replySelectEl.removeEventListener('change', toggleTheme);
            replySelectEl = null;
          }
        }
    })
    .registerSetting({
        setting: 'mshuo-reply-to-see.theme-type-parse',
        label: app.translator.trans('mshuo-reply-to-see.admin.settings.theme-type-parse-lable'),
        class: 'ms-theme-type-parse',
        type: 'select',
        help: app.translator.trans('mshuo-reply-to-see.admin.settings.theme-type-parse-helpText'),
        options: {
          '0': app.translator.trans('mshuo-reply-to-see.admin.theme-type-parse.0'),
          '1': app.translator.trans('mshuo-reply-to-see.admin.theme-type-parse.1'),
        },
        default: '0',
        oncreate: vnode => {
          themeSelectEl = vnode.dom.closest('.Form-group');
          toggleTheme();
        },
        onremove: () => {
          themeSelectEl = null;
        }
    })
    .registerPermission(
      {
        icon: 'fas fa-eye-slash',
        label: app.translator.trans('mshuo-reply-to-see.admin.permissions.reply-to-see-lable'),
        permission: 'post.PassReplyToSee',
      },
      'view'
    );
});