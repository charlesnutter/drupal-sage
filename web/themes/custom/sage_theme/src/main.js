import './styles/app.css';

/**
 * SAGE Theme — client-side behaviour.
 *
 * Dark mode is set server-side via the Drupal theme setting (dark_mode).
 * This module additionally:
 *  - Wires [data-sage-dark-toggle] buttons to toggle the .dark class at runtime
 *  - Persists the user's preference in localStorage so it survives page loads
 *    without a round-trip through Drupal's theme settings
 *  - Applies any stored preference on initial load (overrides the server setting
 *    only when the user has explicitly toggled)
 */
(function () {
  'use strict';

  const STORAGE_KEY = 'sage-dark-mode';
  const root        = document.documentElement;

  /**
   * Apply a stored localStorage preference, if one exists.
   * Runs immediately (before DOMContentLoaded) to avoid a flash of wrong theme.
   */
  (function applyStoredPreference() {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === '1') {
      root.classList.add('dark');
    } else if (stored === '0') {
      root.classList.remove('dark');
    }
    // If null, the server-rendered class (or its absence) stands.
  })();

  document.addEventListener('DOMContentLoaded', function () {

    // Wire every [data-sage-dark-toggle] button.
    document.querySelectorAll('[data-sage-dark-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const isDark = root.classList.toggle('dark');
        localStorage.setItem(STORAGE_KEY, isDark ? '1' : '0');
      });
    });

    // Auto-scroll the message list to the bottom whenever new content is added.
    const messageArea = document.querySelector('.sage-chat__messages');
    if (messageArea) {
      const observer = new MutationObserver(function () {
        messageArea.scrollTop = messageArea.scrollHeight;
      });
      observer.observe(messageArea, { childList: true, subtree: true });
    }

    // Auto-grow the query textarea as the user types.
    // Respects the CSS min-height so the taller starting size is preserved.
    document.querySelectorAll('.sage-textarea').forEach(function (textarea) {
      function resize() {
        textarea.style.height = 'auto';
        const minH = parseInt(getComputedStyle(textarea).minHeight, 10) || 0;
        const maxH = 240; // px — matches max-height: 15rem in CSS
        textarea.style.height = Math.min(Math.max(textarea.scrollHeight, minH), maxH) + 'px';
      }
      textarea.addEventListener('input', resize);
      resize();
    });

  });

})();
