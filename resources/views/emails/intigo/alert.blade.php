{{-- Intigo parcel alert → team inbox (App\Mail\IntigoAlertMail). Links: admin panel only. --}}
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Intigo</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;border-top:4px solid #db142e;">
    <tr><td style="padding:24px 28px;">
      <h1 style="margin:0 0 4px;font-size:18px;">{{ $title }}</h1>
      <p style="margin:0 0 18px;font-size:13px;color:#6b7280;">Colis {{ $ref }} — action requise dans le panneau admin.</p>
      <div style="padding:14px 16px;background:#fef2f2;border-radius:8px;font-size:14px;line-height:22px;white-space:pre-wrap;">{{ $reason }}</div>
      <p style="margin:18px 0 0;font-size:14px;line-height:22px;">
        Corrigez la cause (adresse, téléphone, adresse d'enlèvement, solde Intigo, clé API…), puis cliquez sur
        <b>Retry send</b> sur la commande ou dans la page Intigo. Rien n'est renvoyé automatiquement après un refus d'Intigo.
      </p>
      <p style="margin:18px 0 0;">
        @if($orderUrl)
        <a href="{{ $orderUrl }}" style="display:inline-block;padding:10px 16px;background:#db142e;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;margin-right:8px;">Ouvrir la commande</a>
        @endif
        <a href="{{ $panelUrl }}" style="display:inline-block;padding:10px 16px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;">Page Intigo</a>
      </p>
      <p style="margin:16px 0 0;font-size:11px;color:#9ca3af;">Envoyé le {{ now()->timezone('Africa/Tunis')->format('d/m/Y H:i') }}</p>
    </td></tr>
  </table>
</body>
</html>
