@extends($WRLAHelper::getViewPath("layouts.auth-layout"))

@section('title', 'Forgot Password')

@section('content')

    {{-- Login form --}}
    <div class="w-full md:w-9/12 lg:w-4/12 rounded-lg p-8 mx-6 bg-slate-100 shadow-md dark:bg-slate-800">
        {{-- Back link using text-primary-500 with icon --}}
        <a href="{{ route('wrla.login') }}" class="relative flex items-center gap-2 text-primary-500 -top-5 -left-3">
            <i class="fas fa-arrow-left"></i>
            <span>Back</span>
        </a>

        {{-- Title --}}
        <div class="text-center">
            <h1 class="text-2xl font-medium">Forgotten Your Password?</h1>
            <hr class="w-full md:w-1/3 h-2 my-4 m-auto border-t-2 border-slate-500 dark:border-slate-200" />
        </div>

        <form action="{{ route('wrla.forgot-password.post') }}" method="post" class="flex flex-col gap-6">
            @csrf

            <div>
                @themeComponent('forms.input-text', [
                    'label' => 'Email Address',
                    'error' => $errors->first('email'),
                    'attributes' => Arr::toAttributeBag([
                        'type' => 'email',
                        'name' => 'email',
                        'value' => old('email'),
                        'placeholder' => 'email@address.com',
                        'autofocus' => true,
                        'required' => true
                    ])
                ])
            </div>

            {{-- Captcha --}}
            @error('captcha.error')
                @themeComponent('alert', ['type' => 'error', 'message' => $message, 'class' => '!mb-0'])
            @enderror
            {!! $WRLAHelper::getCaptchaHTML() !!}

            <div>
                @themeComponent('forms.button', [
                    'size' => 'large',
                    'text' => 'Request password reset link',
                    'attributes' => Arr::toAttributeBag([
                        'type' => 'submit'
                    ])
                ])
            </div>

        </form>

    </div>

@endsection
