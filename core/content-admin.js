(function (wp, settings) {
  if (!settings) return;

  var createElement = wp.element.createElement;
  var MainDashboardButton = wp.editPost.__experimentalMainDashboardButton;

  function BackToList() {
    var isRootEntity = wp.data.useSelect(function (select) {
      var editor = select('core/editor');
      return editor.getCurrentPostType() === settings.postType &&
        String(editor.getCurrentPostId()) === settings.postId;
    }, []);

    // Let WordPress handle returning from a nested pattern or another entity.
    if (!isRootEntity) return null;

    return createElement(wp.element.Fragment, null,
      MainDashboardButton && createElement(MainDashboardButton, null,
        createElement(wp.components.Button, {
          href: settings.url,
          label: settings.label,
          showTooltip: true,
          icon: 'arrow-left-alt2',
          size: 'compact'
        })
      ),
      createElement(wp.editor.PluginMoreMenuItem, { href: settings.url }, settings.label)
    );
  }

  // The shared editor header uses the MainDashboardButton slot in WordPress
  // 7.1. Keep the More menu link available on versions without that header slot.
  // A normal link preserves WordPress's unsaved-change confirmation.
  wp.plugins.registerPlugin('ed-content-admin', { render: BackToList });
})(window.wp, window.edContentAdmin);
