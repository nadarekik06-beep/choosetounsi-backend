{{-- Storefront contact form → team inbox (App\Mail\ContactMessageMail). Everything escaped: visitor input. --}}
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Contact</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;border-top:4px solid #db142e;">
    <tr><td style="padding:24px 28px;">
      <h1 style="margin:0 0 4px;font-size:18px;">Nouveau message — formulaire de contact</h1>
      <p style="margin:0 0 18px;font-size:13px;color:#6b7280;">Répondez directement à cet e-mail : la réponse part chez le visiteur.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="font-size:14px;line-height:22px;">
        <tr><td style="color:#6b7280;padding-right:14px;">Sujet</td><td><b>{{ $topic }}</b></td></tr>
        <tr><td style="color:#6b7280;padding-right:14px;">Nom</td><td>{{ $d['name'] }}</td></tr>
        <tr><td style="color:#6b7280;padding-right:14px;">E-mail</td><td><a href="mailto:{{ $d['email'] }}" style="color:#db142e;">{{ $d['email'] }}</a></td></tr>
        @if(!empty($d['phone']))
        <tr><td style="color:#6b7280;padding-right:14px;">Téléphone</td><td>{{ $d['phone'] }}</td></tr>
        @endif
        <tr><td style="color:#6b7280;padding-right:14px;">Langue</td><td>{{ strtoupper($d['locale']) }}</td></tr>
        <tr><td style="color:#6b7280;padding-right:14px;">Compte</td><td>{{ $d['user_id'] ? '#' . $d['user_id'] : 'visiteur (non connecté)' }}</td></tr>
      </table>
      <div style="margin-top:18px;padding:14px 16px;background:#f9fafb;border-radius:8px;font-size:14px;line-height:22px;white-space:pre-wrap;">{{ $d['message'] }}</div>
      <p style="margin:16px 0 0;font-size:11px;color:#9ca3af;">Envoyé le {{ now()->timezone('Africa/Tunis')->format('d/m/Y H:i') }} · IP {{ $d['ip'] ?? '—' }}</p>
    </td></tr>
  </table>
</body>
</html>
