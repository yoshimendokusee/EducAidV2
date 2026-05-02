@component('mail::message')
# Application Approved ✓

Dear {{ $studentName }},

Congratulations! Your application for educational assistance has been **approved**.

Your application ID: **{{ $studentId }}**

You can now claim your aid during the next distribution cycle. Please log in to the EducAid portal to view your status and prepare for distribution.

@component('mail::button', ['url' => $actionUrl ?? '#'])
View Your Dashboard
@endcomponent

**What's Next?**
1. Ensure your information is up to date in your profile
2. Have a valid ID ready for the distribution event
3. Watch for announcements regarding distribution dates

If you have any questions, please contact the EducAid office.

Best regards,<br>
{{ config('mail.from.name') }}
@endcomponent
