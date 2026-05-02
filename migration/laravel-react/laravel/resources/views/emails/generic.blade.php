@component('mail::message')
# {{ $title }}

{{ $message }}

@if($actionUrl)
@component('mail::button', ['url' => $actionUrl])
Take Action
@endcomponent
@endif

Thanks,<br>
{{ config('mail.from.name') }}
@endcomponent
