@extends('legal.layout')

@section('title', 'Privacy Policy')

@section('content')
    <h1>Stashly Privacy Policy</h1>
    <p class="meta">Version draft-1 · Effective [DATE] · Not legal advice</p>

    <h2>Who we are</h2>
    <p>[COMPANY / OWNER NAME], contact [E-MAIL].</p>

    <h2>What we collect</h2>
    <p><strong>Account:</strong> display name, e-mail, a hashed password (never the password itself), the terms version you accepted and when.</p>
    <p><strong>Content you create:</strong> saved links, your titles and notes, tags, collections, favourites.</p>
    <p><strong>Page details we fetch:</strong> title, description, site name and icon/preview-image addresses of pages you save.</p>
    <p><strong>Devices and sessions:</strong> the device name you give at sign-in and sign-in tokens.</p>
    <p><strong>Technical data:</strong> IP address and request identifiers in server and security logs and for rate limiting. Our application logs do not contain passwords, tokens or full web addresses.</p>

    <h2>Why</h2>
    <p>To provide and secure the service, show your library, prevent abuse and fix problems. [LEGAL BASIS if required.]</p>

    <h2>Who receives it</h2>
    <p>Our hosting provider [NAME], our e-mail sending provider [NAME] (for password-reset e-mails), and [OTHERS]. We do not sell your data.</p>

    <h2>Where it is stored and for how long</h2>
    <p>[REGION]. Data is kept until you delete it or your account. Logs are kept [N] days. [Backups: N days.]</p>

    <h2>Your choices</h2>
    <p><strong>Access/export:</strong> download all your data from the app (a JSON file). <strong>Correct:</strong> edit your display name, titles and notes in the app. <strong>Delete:</strong> delete your account in the app; this permanently removes your account, links, collections, tags and tokens. [OTHER RIGHTS BY REGION.]</p>

    <h2>Children</h2>
    <p>Stashly is not intended for children under [AGE].</p>

    <h2>Security</h2>
    <p>Passwords are hashed, traffic uses HTTPS, and sign-in tokens expire after 60 days.</p>

    <h2>Changes</h2>
    <p>We'll tell you in the app when this policy changes.</p>
@endsection
