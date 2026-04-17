@extends('layouts.app')

@section('title', 'Seolinkbuildings - Content Marketplace & Blogger Outreach Platform')

<!-- Meta Description -->
@section('description', 'Seolinkbuildings is a leading content marketplace and blogger outreach platform that helps businesses grow their online presence through strategic link building and digital PR services.')

@section('content')
    @include('components.hero')
    @include('components.features')
    @include('components.how-it-works')
    @include('components.pricing')
    @include('components.testimonials')
    @include('components.newsletter')
    @include('components.cta')
@endsection