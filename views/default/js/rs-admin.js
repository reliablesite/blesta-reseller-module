/**
 * ReliableSite module - Blesta 6 "Paradigm" admin behaviour.
 *
 * Vanilla JS only. Blesta 6 does not load jQuery in the admin panel unless the
 * company-wide "Legacy Support (jQuery)" template setting is enabled, and
 * third-party extensions are expected not to depend on it.
 */
(function (w, d) {
    'use strict';

    /**
     * Themed confirmation dialog.
     *
     * Paradigm auto-binds .modal-confirm-delete / -warning / -success, but all
     * three are registered with `submit: true`. In that mode blestaModalConfirm
     * only ever calls form.submit() - the href branch is unreachable - so a
     * plain <a href> that is not inside a form silently does nothing when the
     * user confirms. Most of this module's destructive actions are GET links,
     * so we bind our own and mirror Paradigm's dialog markup instead.
     *
     * Markup:
     *   <a href="..." class="rs-confirm" data-confirm-message="Are you sure?">
     *   <button type="submit" class="rs-confirm" data-confirm-message="...">
     *
     * Optional: data-confirm-title, data-confirm-button, and class rs-confirm-warning
     * to get the amber variant instead of the destructive red one.
     */
    function bindConfirm(root) {
        (root || d).querySelectorAll('.rs-confirm').forEach(function (el) {
            if (el.dataset.rsConfirmBound) {
                return;
            }
            el.dataset.rsConfirmBound = '1';

            el.addEventListener('click', function (e) {
                e.preventDefault();

                // For a submit button, let the browser surface its own field
                // validation before we ask the user to confirm - otherwise they
                // confirm a destructive action that then fails to submit.
                var owner = el.closest('form');
                if (owner && el.type === 'submit' && typeof owner.reportValidity === 'function'
                    && !owner.reportValidity()) {
                    return;
                }

                if (typeof bootstrap === 'undefined') {
                    // No Bootstrap JS (should not happen in Paradigm) - fall back
                    // to the native dialog rather than silently doing nothing.
                    if (w.confirm(el.getAttribute('data-confirm-message') || 'Are you sure?')) {
                        proceed(el);
                    }
                    return;
                }

                var isWarning = el.classList.contains('rs-confirm-warning');
                var btnClass = isWarning ? 'btn-warning' : 'btn-danger';
                var iconClass = isWarning
                    ? 'bi-exclamation-triangle-fill text-warning'
                    : 'bi-exclamation-triangle-fill text-danger';

                var modalEl = d.createElement('div');
                modalEl.className = 'modal fade';
                modalEl.setAttribute('tabindex', '-1');
                modalEl.innerHTML =
                    '<div class="modal-dialog modal-dialog-centered">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header border-0 pb-0">' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body text-center py-4">' +
                    '<div class="mb-3"><i class="bi ' + iconClass + '" style="font-size: 4rem;"></i></div>' +
                    '<h5 class="modal-title mb-3"></h5>' +
                    '<p class="text-secondary"></p>' +
                    '</div>' +
                    '<div class="modal-footer border-0 justify-content-center">' +
                    '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn ' + btnClass + ' btn-confirm"></button>' +
                    '</div>' +
                    '</div>' +
                    '</div>';

                // Assigned as text, not markup, so a server-supplied name or IP
                // in the message cannot inject HTML.
                modalEl.querySelector('.modal-title').textContent =
                    el.getAttribute('data-confirm-title') || 'Please confirm';
                modalEl.querySelector('.modal-body p').textContent =
                    el.getAttribute('data-confirm-message') || 'Are you sure?';
                modalEl.querySelector('.btn-confirm').textContent =
                    el.getAttribute('data-confirm-button') || 'Confirm';

                d.body.appendChild(modalEl);

                var modal = new bootstrap.Modal(modalEl);
                var confirmed = false;

                modalEl.querySelector('.btn-confirm').addEventListener('click', function () {
                    confirmed = true;
                    this.blur();    // drop focus before hiding, else aria-hidden conflicts
                    modal.hide();
                });

                modalEl.addEventListener('hidden.bs.modal', function () {
                    modalEl.remove();
                    if (confirmed) {
                        proceed(el);
                    }
                });

                modal.show();
            });
        });
    }

    /**
     * Carries out whatever the confirmed element was going to do: follow a
     * link, or submit the form it belongs to.
     */
    function proceed(el) {
        var href = el.getAttribute('href');
        if (href && href !== '#') {
            w.location = href;
            return;
        }

        var form = el.closest('form') ||
            (el.getAttribute('data-form') && d.getElementById(el.getAttribute('data-form')));

        if (!form) {
            return;
        }

        // requestSubmit() honours field validation and submit handlers;
        // submit() bypasses both.
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    /**
     * Click-to-reveal for a sensitive field.
     *
     * Mirrors the pattern core uses for stored payment accounts
     * (admin_clients_account_achinfo.pdt): an input-group with a bi-eye button
     * appended. Core's opens a server round-trip because the value is
     * encrypted at rest and not in the page; the API key is already in the
     * field, so this only flips the input type.
     *
     *   <button data-rs-reveal="api_key"><i class="bi bi-eye"></i></button>
     */
    function bindReveal(root) {
        (root || d).querySelectorAll('[data-rs-reveal]').forEach(function (btn) {
            if (btn.dataset.rsRevealBound) {
                return;
            }
            btn.dataset.rsRevealBound = '1';

            var input = d.getElementById(btn.getAttribute('data-rs-reveal'));
            if (!input) {
                return;
            }

            btn.addEventListener('click', function () {
                var revealing = (input.type === 'password');
                input.type = revealing ? 'text' : 'password';

                var icon = btn.querySelector('i');
                if (icon) {
                    icon.className = 'bi ' + (revealing ? 'bi-eye-slash' : 'bi-eye');
                }

                var label = revealing
                    ? (btn.getAttribute('data-rs-label-hide') || 'Hide')
                    : (btn.getAttribute('data-rs-label-show') || 'Show');
                btn.setAttribute('aria-label', label);
                btn.setAttribute('aria-pressed', revealing ? 'true' : 'false');

                // Keep any tooltip on the button in step with what it now does.
                if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                    var tip = bootstrap.Tooltip.getInstance(btn);
                    if (tip) {
                        tip.setContent({ '.tooltip-inner': label });
                    }
                }
            });
        });
    }

    w.rsBindConfirm = bindConfirm;
    w.rsBindReveal = bindReveal;

    function init() {
        bindConfirm(d);
        bindReveal(d);
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
