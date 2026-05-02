@component('mail::message')
# Document Processing Update 📄

Dear {{ $studentName }},

Your **{{ $documentType }}** has been {{ $statusLabel }}.

@if($status === 'verified')
✓ Your document has been verified successfully and is now approved.
@elseif($status === 'rejected')
✗ Your document was not accepted. Please resubmit with correct information.
@elseif($status === 'submitted')
⏳ Your document has been received and is being processed.
@elseif($status === 'processed')
✓ Your document has been processed successfully.
@endif

@if($message)
**Additional Notes:**
{{ $message }}
@endif

@component('mail::button', ['url' => $actionUrl ?? '#'])
View Your Documents
@endcomponent

If you have questions about your document status, please contact the EducAid office.

Best regards,<br>
{{ config('mail.from.name') }}
@endcomponent
