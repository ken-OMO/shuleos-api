ShuleOS School Account Activation

@if($schoolName)
School: {{ $schoolName }}
@endif
@if($schoolCode)
School code: {{ $schoolCode }}
@endif

Your verification code is:

{{ $otp }}

This code expires in {{ $expiresInMinutes }} minutes.

Use this code only to activate your school account.

If you did not attempt to activate this account, do not share this code with anyone.