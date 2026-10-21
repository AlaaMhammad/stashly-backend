@extends('legal.layout')

@section('title', 'Terms of Service')

@section('content')
    <h1>Stashly Terms of Service</h1>
    <p class="meta">Version draft-1 · Effective [DATE] · Not legal advice</p>

    <h2>1. Service</h2>
    <p>Stashly lets you save links, add notes, tags and collections, and view basic page details, operated by [COMPANY / OWNER NAME] ("we").</p>

    <h2>2. Your account</h2>
    <p>You need an account with a valid e-mail and a password of at least 8 characters. Keep your password secret; you are responsible for activity on your account.</p>

    <h2>3. Your content</h2>
    <p>Links, titles, notes, tags and collections you save stay yours. You give us permission to store and process them only to run Stashly for you. Don't save links or notes that are unlawful or that you have no right to store.</p>

    <h2>4. Page details</h2>
    <p>When you save or preview a link, our servers visit the public web address to read its title, description, site name, icon address and preview-image address. We store that text and those addresses; we do not download or store images or videos. Addresses that point to private or internal networks are blocked.</p>

    <h2>5. Acceptable use</h2>
    <p>No abuse, scraping of the service, attempts to reach internal systems, or excessive requests. We apply rate limits and may suspend accounts that break these rules.</p>

    <h2>6. Export and deletion</h2>
    <p>You can export your data at any time and delete your account in the app. Deletion permanently removes your account, links, collections, tags and sign-in tokens. [Backups are deleted within [N] days.]</p>

    <h2>7. Availability</h2>
    <p>The service is provided "as is" without guarantees; we may change or end features with [N] days' notice where practical.</p>

    <h2>8. Liability</h2>
    <p>To the extent the law allows, we are not liable for indirect losses or for the content of websites you save. [GOVERNING LAW / JURISDICTION.]</p>

    <h2>9. Changes</h2>
    <p>We may update these terms; you'll be asked to accept a new version when it matters.</p>

    <h2>10. Contact</h2>
    <p>[CONTACT E-MAIL]</p>
@endsection
