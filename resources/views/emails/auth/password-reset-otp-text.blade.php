{{ $appName }} password reset

@if (filled($name))
Hi {{ $name }},
@else
Hi,
@endif

Your password reset code is: {{ $code }}

Enter it in the {{ $appName }} app to choose a new password. The code expires in {{ $minutes }} minutes and can be used once.

If you didn't request this, ignore this email. Your password will not change. Never share this code with anyone.
