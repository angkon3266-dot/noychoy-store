{{-- Why a save was refused. The admin layout prints no $errors of its own, so
     a page without this showed nothing after a failed validate() and the owner
     saw "it isn't saving". Used via @section('flash'), which also raises the
     red toast after a background submit. --}}
@if($errors->any())
    <div class="m-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
        <p class="font-medium">Not saved — please fix {{ $errors->count() === 1 ? 'this' : 'these' }} and try again:</p>
        <ul class="mt-1 list-disc pl-5 space-y-0.5">
            @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </div>
@endif
