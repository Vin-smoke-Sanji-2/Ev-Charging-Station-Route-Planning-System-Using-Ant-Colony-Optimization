<x-mail::message>
# Your login code

Use this code to finish logging in to {{ config('app.name') }}:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

This code expires in 10 minutes. If you didn't try to log in, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
