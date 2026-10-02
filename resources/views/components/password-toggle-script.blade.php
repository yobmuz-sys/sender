{{--
    Password visibility, written once and shared by both shells.

    Progressive enhancement: with scripting unavailable the fields are still
    password fields and the forms still submit, so nothing here may be required
    for a page to be usable.

    Each toggle carries its own labels, because two toggles both called "Show
    password" tell a screen reader nothing about which field they belong to.
--}}
<script>
    (function () {
        var toggles = document.querySelectorAll('[data-password-toggle]');

        toggles.forEach(function (toggle) {
            var input = document.getElementById(toggle.getAttribute('aria-controls'));

            if (!input) {
                return;
            }

            var label = toggle.querySelector('[data-password-label]');
            var revealIcon = toggle.querySelector('[data-password-icon="show"]');
            var hideIcon = toggle.querySelector('[data-password-icon="hide"]');

            toggle.addEventListener('click', function () {
                var reveal = input.type === 'password';

                input.type = reveal ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');

                if (label) {
                    label.textContent = reveal
                        ? toggle.getAttribute('data-hide-label')
                        : toggle.getAttribute('data-show-label');
                }

                if (revealIcon && hideIcon) {
                    revealIcon.classList.toggle('hidden', reveal);
                    hideIcon.classList.toggle('hidden', !reveal);
                }

                // Keep the caret where it was rather than jumping to the start.
                var end = input.value.length;

                input.focus();

                try {
                    input.setSelectionRange(end, end);
                } catch (error) {
                    // Some input types refuse selection APIs; focus alone is enough.
                }
            });
        });
    })();
</script>