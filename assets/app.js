/* Death Plan Switch
 *
 * The app works without this file. It adds four conveniences:
 *   - asking before a few actions that are awkward to undo
 *   - guessing the timezone in the installer
 *   - noticing the first scheduler run in the installer
 *   - testing the data folder from the browser, when the server cannot
 */
(function () {
  'use strict';

  // "Are you sure?" on elements carrying data-confirm.
  document.addEventListener('click', function (event) {
    var el = event.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });

  // Installer: preselect the visitor's timezone.
  var zone = document.querySelector('select[data-timezone-guess]');
  if (zone) {
    try {
      var guess = Intl.DateTimeFormat().resolvedOptions().timeZone;
      for (var i = 0; i < zone.options.length; i++) {
        if (zone.options[i].value === guess) {
          zone.selectedIndex = i;
          break;
        }
      }
    } catch (e) { /* keep the default */ }
  }

  // Installer: reload when the first scheduler run arrives.
  var poll = document.querySelector('[data-poll]');
  if (poll) {
    var timer = window.setInterval(function () {
      fetch(poll.getAttribute('data-poll'), { cache: 'no-store', credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data && data.ticked) {
            window.clearInterval(timer);
            window.location.reload();
          }
        })
        .catch(function () { /* try again next time */ });
    }, 5000);
  }

  // Data folder test, run from the browser. The form carries the address of
  // a harmless probe file and the text inside it. If the browser can read
  // that text, the folder is exposed.
  function probe(form) {
    var found = form.querySelector('input[name="found"]');
    return fetch(form.getAttribute('data-probe-url'), { cache: 'no-store', credentials: 'omit', redirect: 'follow' })
      .then(function (response) { return response.text(); })
      .then(function (body) {
        found.value = body.indexOf(form.getAttribute('data-probe-secret')) === -1 ? 'protected' : 'exposed';
      })
      .catch(function () {
        found.value = 'unreachable';
      });
  }

  Array.prototype.forEach.call(document.querySelectorAll('form[data-probe-url]'), function (form) {
    if (form.hasAttribute('data-probe-auto')) {
      // Installer: run it straight away and show the result in the checklist.
      probe(form).then(function () {
        var found = form.querySelector('input[name="found"]').value;
        var item = document.getElementById('probe-item');
        var hint = document.getElementById('probe-hint');
        if (!item || !hint) { return; }
        var mark = item.querySelector('.mark');
        if (found === 'protected') {
          item.className = 'is-ok';
          mark.textContent = '✓';
          hint.textContent = 'Checked from your browser: the request was refused.';
        } else if (found === 'exposed') {
          item.className = 'is-todo';
          mark.textContent = '✗';
          hint.textContent = 'Your browser was able to open a test file in the data folder. Block the folder (see the README), then reload this page.';
        } else {
          hint.textContent = 'Neither the server nor your browser could run the test. Check the web address of this page is correct.';
        }
      });
    } else {
      // Settings: run it when the button is pressed, then submit the result.
      form.addEventListener('submit', function (event) {
        var found = form.querySelector('input[name="found"]');
        if (found.value !== '') { return; }
        event.preventDefault();
        probe(form).then(function () { form.submit(); });
      });
    }
  });
})();
