@extends('layouts.stafflayouts')

@section('content')
    <div class="container">
        @include('notifications._list', ['rolePrefix' => 'staff', 'notifications' => $notifications])
    </div>
@endsection
