@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-900">Welcome back, {{ auth()->user()->name }}</h2>
        <p class="mt-1 text-sm text-gray-500">Here's what's happening today.</p>
    </div>

    <div class="rounded-xl bg-white p-8 shadow-sm ring-1 ring-gray-200">
        <p class="text-sm text-gray-600">Your dashboard content will appear here.</p>
    </div>
@endsection
