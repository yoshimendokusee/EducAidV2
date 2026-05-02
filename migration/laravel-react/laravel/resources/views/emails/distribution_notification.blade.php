@component('mail::message')
# Distribution Notification 📢

Dear {{ $studentName }},

Great news! You have been selected to receive educational assistance in the **{{ $distributionName }}** distribution cycle.

@if($amount)
**Assistance Amount:** ₱{{ number_format($amount, 2) }}
@endif

**Distribution Details:**
- Please visit your account to claim your aid
- Have a valid ID ready when claiming
- For more information, visit the EducAid office

@component('mail::button', ['url' => $actionUrl ?? '#'])
View Distribution Info
@endcomponent

Thank you for being part of the EducAid program.

Best regards,<br>
{{ config('mail.from.name') }}
@endcomponent
