<x-center>
    <form class="flex flex-col gap-2" wire:submit.prevent="submit" id="register">
        <div class="flex flex-col items-center mt-4 mb-10">
            <x-logo />
            <h1 class="text-2xl text-center text-white mt-2">{{ __('auth.sign_up_title') }} </h1>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <x-form.input name="first_name" type="text" :label="__('general.input.first_name')" :placeholder="__('general.input.first_name_placeholder')" wire:model="first_name"
                :required="!in_array('first_name', config('settings.optional_fields'))" noDirty />
            <x-form.input name="last_name" type="text" :label="__('general.input.last_name')" :placeholder="__('general.input.last_name_placeholder')" wire:model="last_name"
                :required="!in_array('last_name', config('settings.optional_fields'))" noDirty />

            <x-form.input name="email" type="email" :label="__('general.input.email')" :placeholder="__('general.input.email_placeholder')" required wire:model="email"
                noDirty />

            <x-form.input name="password" type="password" :label="__('Password')" :placeholder="__('Your password')" wire:model="password"
                required noDirty />
            <x-form.input name="password_confirm" type="password" :label="__('Password')" :placeholder="__('Confirm your password')"
                wire:model="password_confirmation" required noDirty />

            <x-form.properties :customProperties="$custom_properties" :properties="$properties" />
        </div>

        <x-captcha :form="'register'" />

        <x-button.primary class="w-full">{{ __('Sign up') }}</x-button.primary>

        <div class="text-white text-center rounded-md py-2 mt-6 text-sm">
            {{ __('Already have an account?') }}
            <a class="text-sm text-secondary-500 text-secondary hover:underline" href="{{ route('login') }}"
                wire:navigate>
                {{ __('Sign in') }}
            </a>
        </div>
    </form>
</x-center>
