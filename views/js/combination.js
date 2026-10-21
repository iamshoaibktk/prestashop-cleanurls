/**
 * Clean URLs - selects the product combination named in the URL anchor.
 *
 * Without IDs in the path, a link to one combination looks like
 *   /men/hummingbird-printed-t-shirt.html#/1-size-s/11-color-black
 * Each anchor part starts with an attribute ID; this picks those options so PrestaShop
 * loads the right price, stock and images.
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
(function () {
  'use strict';

  function select() {
    var hash = decodeURIComponent(window.location.hash || '');
    if (hash.indexOf('#/') !== 0) {
      return;
    }
    var ids = hash.slice(2).split('/').map(function (part) {
      var m = /^(\d+)-/.exec(part);
      return m ? m[1] : null;
    }).filter(Boolean);

    var changed = null;
    ids.forEach(function (id) {
      var radio = document.querySelector('input[name^="group["][value="' + id + '"]');
      if (radio && !radio.checked) {
        radio.checked = true;
        changed = radio;
        return;
      }
      var option = document.querySelector('select[name^="group["] option[value="' + id + '"]');
      if (option && !option.selected) {
        option.selected = true;
        changed = option.parentNode;
      }
    });
    if (changed) {
      changed.dispatchEvent(new Event('change', {bubbles: true}));
    }
  }

  // The theme attaches its "change" listeners late; wait until PrestaShop's front object is
  // ready, then give it a moment so the update request is not ignored.
  function start() {
    var tries = 0;
    (function wait() {
      if ((window.prestashop && typeof window.prestashop.on === 'function') || ++tries > 40) {
        setTimeout(select, 250);
      } else {
        setTimeout(wait, 50);
      }
    })();
  }
  if (document.readyState === 'complete') {
    start();
  } else {
    window.addEventListener('load', start);
  }
})();
