@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Hello Message with emoji party pooper -->
    <div class="mb-4">
        <h1 class="h3">Welcome back, {{ auth()->user()->name }}! 🎉</h1>
        <p class="text-muted">Here's a quick overview of your dashboard.</p>
    </div>
</div>
@endsection