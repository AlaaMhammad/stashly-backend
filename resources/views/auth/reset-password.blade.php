<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex">
    <title>Reset your password · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #f5f5f5; color: #1a1a1a; }
        main { max-width: 26rem; margin: 3rem auto; padding: 2rem 1.5rem; background: #fff; border-radius: 12px; }
        h1 { font-size: 1.4rem; margin: 0 0 1.25rem; }
        label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
        input { box-sizing: border-box; width: 100%; padding: .65rem .75rem; font-size: 1rem; border: 1px solid #bbb; border-radius: 8px; }
        button { margin-top: 1.5rem; width: 100%; padding: .75rem; font-size: 1rem; font-weight: 600; color: #fff; background: #1a1a1a; border: 0; border-radius: 8px; cursor: pointer; }
        button[disabled] { opacity: .6; cursor: wait; }
        .notice { margin-top: 1.25rem; padding: .75rem; border-radius: 8px; }
        .error { background: #fdecec; color: #8a1c1c; }
        .success { background: #e8f6ec; color: #1d5e33; }
        .hint { font-size: .875rem; color: #555; margin-top: .35rem; }
    </style>
</head>
<body>
<main>
    <h1>Reset your password</h1>

    @if ($token === '' || $email === '')
        <p class="notice error">This reset link is incomplete. Request a new one from the Stashly app.</p>
    @else
        <form id="reset-form" novalidate
              data-endpoint="{{ url('/api/v1/auth/reset-password') }}"
              data-token="{{ $token }}"
              data-email="{{ $email }}">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required>
            <p class="hint">8 to 128 characters.</p>

            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="128" required>

            <button type="submit">Set new password</button>
        </form>
        <p id="result" class="notice" hidden></p>
    @endif
</main>

<script>
(function () {
    var form = document.getElementById('reset-form');
    if (!form) return;
    var result = document.getElementById('result');
    var button = form.querySelector('button');

    function show(kind, text) {
        result.className = 'notice ' + kind;
        result.textContent = text;
        result.hidden = false;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var password = form.password.value;
        var confirmation = form.password_confirmation.value;

        if (password !== confirmation) {
            show('error', 'The two passwords do not match.');
            return;
        }

        button.disabled = true;
        fetch(form.dataset.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                email: form.dataset.email,
                token: form.dataset.token,
                password: password,
                password_confirmation: confirmation
            })
        }).then(function (response) {
            return response.json().then(function (body) { return { ok: response.ok, body: body }; });
        }).then(function (res) {
            if (res.ok) {
                form.hidden = true;
                show('success', 'Your password has been reset. You can now sign in to the Stashly app.');
                return;
            }
            var error = (res.body && res.body.error) || {};
            if (error.code === 'TOKEN_INVALID_OR_EXPIRED') {
                form.hidden = true;
                show('error', 'This reset link is invalid or has expired. Request a new one from the Stashly app.');
            } else if (error.code === 'VALIDATION_FAILED' && error.details && error.details.password) {
                show('error', [].concat(error.details.password).join(' '));
            } else if (error.code === 'RATE_LIMITED') {
                show('error', 'Too many attempts. Please wait a moment and try again.');
            } else {
                show('error', 'Something went wrong. Please try again.');
            }
        }).catch(function () {
            show('error', 'Could not reach the server. Check your connection and try again.');
        }).then(function () {
            button.disabled = false;
        });
    });
})();
</script>
</body>
</html>
