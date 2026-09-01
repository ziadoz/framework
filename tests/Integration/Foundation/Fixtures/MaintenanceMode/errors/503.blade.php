@if (! empty($message))
    {{ $message }}
@else
    {{ __('Service Unavailable') }}
@endif
