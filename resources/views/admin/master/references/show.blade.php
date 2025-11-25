@extends('admin.layouts.app')
@section('title', 'View Reference')

@section('content')
    <div class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-12">
                    <div class="box">
                        <div class="box-header with-border">
                            <a href="{{ route('references.index') }}">
                                <button class="btn btn-danger btn-sm pull-right" type="submit">
                                    <i class="mdi mdi-keyboard-backspace mr-2"></i>
                                    @lang('view_pages.back')
                                </button>
                            </a>
                        </div>

                        <div class="col-sm-12">
                            @if(session('success'))
                                <div class="alert alert-success">{{ session('success') }}</div>
                            @endif

                            <h3>@lang('view_pages.reference'): {{ $reference->name }}</h3>
                            <p><strong>@lang('view_pages.short_name'):</strong> {{ $reference->short_name }}</p>

                            <a href="{{ route('references.edit', $reference) }}" class="btn btn-warning">@lang('view_pages.edit')</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
