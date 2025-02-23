(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.viewsAccordionTable = {
    attach: function (context, settings) {
      $('.views-accordion-table-wrapper .accordion-header', context).once('accordion-handler').on('click', function() {
        var $header = $(this);
        var $content = $header.next('.accordion-content');
        
        $header.toggleClass('is-open');
        $header.attr('aria-expanded', $header.hasClass('is-open'));
        $content.slideToggle();
      });
    }
  };

})(jQuery, Drupal);
