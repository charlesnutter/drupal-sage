(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.sageDiscovery = {
    attach(context) {
      const form = context.querySelector
        ? context.querySelector('#sage-discovery-form')
        : document.getElementById('sage-discovery-form');

      if (!form || form.dataset.sageAttached) return;
      form.dataset.sageAttached = '1';

      // DOM references — most live in the page template, not the form.
      const chatShell      = document.querySelector('.sage-chat');
      const transcript     = document.getElementById('sage-transcript');
      const textarea       = document.getElementById('sage-textarea');
      const submitBtn      = document.getElementById('sage-submit-btn');
      const ageRangeSelect = document.getElementById('sage-age-range');

      // Entity-type multi-select dropdown
      const entityToggle   = document.getElementById('sage-entity-toggle');
      const entityDropdown = document.getElementById('sage-entity-dropdown');
      const entityLabelEl  = document.getElementById('sage-entity-label');
      const entityChecks   = form ? form.querySelectorAll('.sage-multiselect__check') : [];

      const chatUrl = drupalSettings.sage.chatUrl;
      const siteUrl = drupalSettings.sage.siteUrl;

      let hasStarted = false;

      // ── Entity-type multi-select ─────────────────────────────────────── //

      if (entityToggle && entityDropdown) {
        entityToggle.addEventListener('click', function (e) {
          e.stopPropagation();
          const opening = entityDropdown.hidden;
          entityDropdown.hidden = !opening;
          entityToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });

        // Close when clicking outside the widget
        document.addEventListener('click', function () {
          entityDropdown.hidden = true;
          entityToggle.setAttribute('aria-expanded', 'false');
        });

        // Keep clicks inside the dropdown from bubbling to document
        entityDropdown.addEventListener('click', function (e) {
          e.stopPropagation();
        });

        // Update the toggle label whenever a checkbox changes
        entityChecks.forEach(function (check) {
          check.addEventListener('change', syncEntityLabel);
        });
      }

      function syncEntityLabel() {
        if (!entityLabelEl) return;
        const checked = Array.from(entityChecks).filter(function (c) { return c.checked; });
        if (checked.length === 0) {
          entityLabelEl.textContent = 'All types';
        } else if (checked.length === 1) {
          const span = checked[0].closest('label').querySelector('span');
          entityLabelEl.textContent = span ? span.textContent : checked[0].value;
        } else {
          entityLabelEl.textContent = checked.length + ' types';
        }
      }

      function getActiveEntityTypes() {
        return Array.from(entityChecks)
          .filter(function (c) { return c.checked; })
          .map(function (c) { return c.value; });
      }

      // ── Submit handling ──────────────────────────────────────────────── //

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        const query = textarea.value.trim();
        if (!query) return;

        const ageRange    = ageRangeSelect ? ageRangeSelect.value : 'All';
        const entityTypes = getActiveEntityTypes();

        let message = query;
        const filters = [];
        if (ageRange !== 'All') filters.push('Age range: ' + ageRange);
        if (filters.length) {
          message += '\n\nFilters: ' + filters.join(', ') + '.';
        }

        textarea.value = '';
        resetTextareaHeight();
        sendMessage(message, !hasStarted, entityTypes);
      });

      // Allow Shift+Enter for newlines; plain Enter submits.
      textarea && textarea.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
      });

      // ── Core send function ───────────────────────────────────────────── //

      function sendMessage(message, isFirst, entityTypes) {
        if (!hasStarted) {
          hasStarted = true;
          flipInputToBottom();
        }

        appendMessage('user', message);
        const typingEl = appendTyping();
        setDisabled(true);

        fetch(chatUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            message: message,
            reset: isFirst,
            ...(isFirst ? { entity_type_hints: entityTypes || [] } : {}),
          }),
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            typingEl.remove();
            if (data.error) {
              appendMessage('error', data.error);
            }
            else if (data.tool_results) {
              renderResults(
                data.response,
                data.tool_results,
                data.search_suggestions || [],
                data.collection_title || ''
              );
            }
            else {
              appendMessage('agent', data.response);
            }
          })
          .catch(function () {
            typingEl.remove();
            appendMessage('error', 'Could not reach the SAGE service. Please try again.');
          })
          .finally(function () {
            setDisabled(false);
            textarea && textarea.focus();
          });
      }

      // ── Render helpers ───────────────────────────────────────────────── //

      function renderResults(agentText, toolResults, searchSuggestions, collectionTitle) {
        const el = document.createElement('div');
        el.className = 'sage-message sage-message--agent';

        let body = '';
        const cleanText = stripSuggestionsCue(extractMessageText(agentText));

        if (collectionTitle) {
          body += '<h3 class="sage-results-title">' + escapeHtml(collectionTitle) + '</h3>';
        }

        if (cleanText) {
          body += '<div class="sage-message__bubble">' + marked.parse(cleanText) + '</div>';
        }

        const results = toolResults.results || [];
        if (results.length) {
          body += '<div class="sage-results">';
          results.forEach(function (r) {
            body += '<a class="sage-result-card" href="' + escapeHtml(r.url) + '">'
              + '<div class="sage-result-card__title">' + escapeHtml(r.title || 'Node ' + r.nid) + '</div>'
              + (r.summary ? '<div class="sage-result-card__summary">' + escapeHtml(r.summary) + '</div>' : '')
              + '</a>';
          });
          body += '</div>';
        }

        if (searchSuggestions.length) {
          body += '<p class="sage-suggestions-label">Suggested search terms</p>'
               +  '<p class="sage-suggestions-desc">These topics are worth exploring to learn more:</p>'
               +  '<div class="sage-suggestions">';
          searchSuggestions.forEach(function (term) {
            const encoded = encodeURIComponent(String(term)).replace(/%20/g, '+');
            body += '<a class="sage-suggestion-link" href="' + siteUrl + '/search/node?keys=' + encoded + '" target="_blank">'
              + escapeHtml(String(term)) + '</a>';
          });
          body += '</div>';
        }

        el.innerHTML = '<div class="sage-message__bubble">' + body + '</div>';
        transcript.appendChild(el);
        scrollToBottom();
      }

      function appendMessage(role, text) {
        const el = document.createElement('div');
        el.className = 'sage-message sage-message--' + role;
        const display = role === 'agent' ? extractMessageText(text) : text;
        const content = role === 'agent'
          ? marked.parse(display)
          : escapeHtml(display).replace(/\n/g, '<br>');
        el.innerHTML = '<div class="sage-message__bubble">' + content + '</div>';
        transcript.appendChild(el);
        scrollToBottom();
        return el;
      }

      function appendTyping() {
        const el = document.createElement('div');
        el.className = 'sage-message sage-message--agent';
        el.innerHTML = '<div class="sage-typing">'
          + '<span></span><span></span><span></span>'
          + '</div>';
        transcript.appendChild(el);
        scrollToBottom();
        return el;
      }

      // FLIP animation: smoothly moves the input from its centered position
      // to the bottom of the viewport as the chat shell becomes active.
      function flipInputToBottom() {
        const inputArea = document.querySelector('.sage-input-area');
        if (!inputArea || !chatShell) {
          chatShell && chatShell.classList.add('sage-chat--active');
          return;
        }

        // F — record where the input is right now (centered layout)
        const first = inputArea.getBoundingClientRect();

        // Activate the layout (instant DOM change: messages expand, empty state collapses)
        chatShell.classList.add('sage-chat--active');

        // L — record where the input landed after the layout change
        const last = inputArea.getBoundingClientRect();
        const deltaY = first.top - last.top;

        // Skip animation if the movement is negligible
        if (Math.abs(deltaY) < 2) return;

        // I — invert: push input back to its old visual position instantly
        inputArea.style.transition = 'none';
        inputArea.style.transform = 'translateY(' + deltaY + 'px)';

        // Force the browser to apply the invert before starting the animation
        inputArea.getBoundingClientRect();

        // P — play: animate from the inverted offset to its true resting position
        inputArea.style.transition = 'transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
        inputArea.style.transform = 'translateY(0)';

        inputArea.addEventListener('transitionend', function () {
          inputArea.style.transition = '';
          inputArea.style.transform = '';
        }, { once: true });
      }

      function scrollToBottom() {
        const area = document.querySelector('.sage-chat__messages');
        if (area) area.scrollTop = area.scrollHeight;
      }

      function setDisabled(state) {
        if (textarea)  textarea.disabled  = state;
        if (submitBtn) submitBtn.disabled = state;
      }

      function resetTextareaHeight() {
        if (textarea) {
          textarea.style.height = 'auto';
          const minH = parseInt(getComputedStyle(textarea).minHeight, 10) || 80;
          textarea.style.height = minH + 'px';
        }
      }

      // Strip closing sentences that direct the reader to "use the search
      // suggestions below" — the UI now provides that context directly under
      // the heading, so these sentences create a confusing false reference.
      function stripSuggestionsCue(text) {
        if (!text) return text;
        return text
          .replace(/[^\n.!?]*\b(?:search suggestions?|suggested search terms?|suggestions? below|search terms? below)\b[^\n.!?]*[.!?]/gi, '')
          .replace(/[ \t]{2,}/g, ' ')
          .trim();
      }

      // If the model slips raw JSON past the PHP layer, extract the message
      // field rather than dumping the JSON blob into the chat.
      function extractMessageText(text) {
        if (!text) return text;
        const trimmed = text.trim();
        // Pure JSON object
        if (trimmed.startsWith('{')) {
          try {
            const parsed = JSON.parse(trimmed);
            if (parsed && parsed.message) return parsed.message;
          } catch (e) { /* not valid JSON */ }
        }
        // JSON embedded after prose — strip everything from the first { onward
        // if it contains a response_type key.
        const idx = trimmed.indexOf('{"response_type"');
        if (idx === -1) {
          // Also check without quotes
          const idx2 = trimmed.indexOf('"response_type"');
          if (idx2 !== -1) {
            const before = trimmed.slice(0, trimmed.lastIndexOf('{', idx2)).trim();
            return before || trimmed;
          }
          return text;
        }
        const before = trimmed.slice(0, idx).trim();
        try {
          const parsed = JSON.parse(trimmed.slice(idx));
          return (before ? before + '\n\n' : '') + (parsed.message || '');
        } catch (e) {
          return before || text;
        }
      }

      function escapeHtml(str) {
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }
    },
  };

})(Drupal, drupalSettings);
