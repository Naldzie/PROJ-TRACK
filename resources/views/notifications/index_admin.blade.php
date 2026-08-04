@extends('layouts.app')

@section('content')
    <div class="container">
        @include('notifications._list', ['rolePrefix' => 'admin', 'notifications' => $notifications])
    </div>
@endsection
