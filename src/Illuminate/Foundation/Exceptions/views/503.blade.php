@extends('errors::minimal')

@section('title', __('Service Unavailable'))
@section('code', '503')
@section('message')
    @if (! empty($message))
        {{ $message }}
    @else
        {{ __('Service Unavailable') }}
    @endif
@endsection
