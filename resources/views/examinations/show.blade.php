@extends('layouts.app')

@section('title', __('examination.staff.detail_title').' — '.__('app.name'))
@section('content_width', 'max-w-7xl')

@section('content')
    @include('examinations._workspace')
@endsection