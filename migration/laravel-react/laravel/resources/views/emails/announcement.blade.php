@component('mail::message')
# {{ $title }}

{{ $message }}

@if($actionUrl)
@component('mail::button', ['url' => $actionUrl])
Learn More
@endcomponent
@endif

---
*This is an announcement from the EducAid General Trias program.*

Best regards,<br>
{{ config('mail.from.name') }}
@endcomponent
