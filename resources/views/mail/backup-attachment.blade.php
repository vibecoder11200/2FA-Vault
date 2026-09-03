@component('mail::message')
# 2FA-Vault Encrypted Backup

Please find your encrypted backup file **{{ $filename }}** attached to this email.

The attachment is a password-encrypted backup (AES-256-GCM). It can only be restored with your backup password, which is not included in this email — keep it safe.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
