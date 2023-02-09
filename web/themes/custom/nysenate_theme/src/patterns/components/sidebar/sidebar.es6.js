/**
 * @file
 * Behaviors for the Sidebar.
 */
/* eslint-disable max-len */

!((document, Drupal, $) => {
  'use strict';

  /**
   * Setup and attach the Sidebar behaviors.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.sidebar = {
    attach: function (context) {
      const $this = this;
      const sidebarToggle = $('.sidebar-toggle', context);
      const header = $('#js-sticky--dashboard');

      sidebarToggle.once('sidebarToggle').each(function () {
        const sidebarToggle = $(this);

        sidebarToggle.click(function () {
          const sidebar = $('.sidebar');
          const body = $('body');

          if (sidebar.hasClass('show')) {
            sidebar.removeClass('show');
            body.removeClass('.sidebar-open');
            $(this).removeClass('show');
          }
          else {
            sidebar.addClass('show');
            body.addClass('.sidebar-open');
            $(this).addClass('show');
          }
        });
        $(window).resize($this.debounce(() => $this.onResize(sidebarToggle)));

        $this.onResize(header);
      });
    },
    onResize: function (header) {
      const headerBottom =
        header.offset().top + header.outerHeight();
      const sidebar = $('.sidebar');
      sidebar.css('--top', `${headerBottom}px`);
    },
    debounce: function (func, timeout = 300) {
      let timer;
      return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
          func.apply(this, args);
        }, timeout);
      };
    }
  };
})(document, Drupal, jQuery);