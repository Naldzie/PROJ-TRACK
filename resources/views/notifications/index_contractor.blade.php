@extends('layouts.contractorlayouts')

@section('content')
    <div class="container">
        @include('notifications._list', ['rolePrefix' => 'contractor', 'notifications' => $notifications])
    </div>
@endsection
