<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    public $photo = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'photo' => ['nullable', 'mimetypes:image/webp', 'extensions:webp', 'max:10240'],
        ]);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $temporaryDisk = FileUploadConfiguration::disk();
        $temporaryPhotoPath = null;
        $previousPhotoPath = null;

        if ($this->photo !== null) {
            $previousPhotoUrl = $user->getRawOriginal('profile_photo_url');
            $temporaryPhotoPath = FileUploadConfiguration::path($this->photo->getFilename(), false);
            $photoPath = 'profile-photos/'.Str::uuid().'.webp';

            $photoCopied = $temporaryDisk === 's3'
                ? Storage::disk('s3')->copy($temporaryPhotoPath, $photoPath)
                : Storage::disk($temporaryDisk)->copyToDisk('s3', $temporaryPhotoPath, $photoPath);

            if (! $photoCopied) {
                throw ValidationException::withMessages([
                    'photo' => __('The photo could not be uploaded. Please try again.'),
                ]);
            }

            $user->profile_photo_url = $photoPath;

            if ($previousPhotoUrl !== null && str_starts_with($previousPhotoUrl, 'profile-photos/')) {
                $previousPhotoFilename = substr($previousPhotoUrl, strlen('profile-photos/'));

                if (basename($previousPhotoFilename) === $previousPhotoFilename) {
                    $previousPhotoPath = $previousPhotoUrl;
                }
            }
        }

        $user->save();

        if ($previousPhotoPath !== null) {
            if (! Storage::disk('s3')->delete($previousPhotoPath)) {
                report(new \RuntimeException('Failed to delete the previous profile photo from S3.'));
            }
        }

        if ($temporaryPhotoPath !== null) {
            if (! Storage::disk($temporaryDisk)->delete([
                $temporaryPhotoPath,
                $temporaryPhotoPath.'.json',
            ])) {
                report(new \RuntimeException('Failed to delete the temporary profile photo from S3.'));
            }

            $this->reset('photo');
        }

        $this->dispatch('profile-updated', name: $user->name, photoUrl: $user->profile_photo_url);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section>
    <header class="space-y-1">
        <flux:heading size="lg">
            {{ __('Profile Information') }}
        </flux:heading>

        <flux:text>
            {{ __("Update your account's profile information and email address.") }}
        </flux:text>
    </header>

    <form wire:submit="updateProfileInformation" class="space-y-5">
        <x-profile.avatar-upload
            model="photo"
            :current-url="auth()->user()->profile_photo_url"
            :user-name="auth()->user()->name"
        />

        <flux:field>
            <flux:label for="name">{{ __('Name') }}</flux:label>
            <flux:input wire:model="name" id="name" name="name" type="text" required autofocus autocomplete="name" />
            <flux:error name="name" />
        </flux:field>

        <flux:field>
            <flux:label for="email">{{ __('Email') }}</flux:label>
            <flux:input wire:model="email" id="email" name="email" type="email" required autocomplete="username" />
            <flux:error name="email" />

            @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                <flux:callout class="mt-3" variant="warning">
                    <flux:callout.text>
                        {{ __('Your email address is unverified.') }}
                    </flux:callout.text>
                    <flux:button type="button" wire:click="sendVerification" variant="ghost" size="sm" class="mt-2">
                        {{ __('Click here to re-send the verification email.') }}
                    </flux:button>
                </flux:callout>

                @if (session('status') === 'verification-link-sent')
                    <flux:callout class="mt-3" variant="success">
                        <flux:callout.text>
                            {{ __('A new verification link has been sent to your email address.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif
            @endif
        </flux:field>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <flux:text
                x-data="{ shown: false }"
                x-on:profile-updated.window="shown = true; setTimeout(() => shown = false, 2000)"
                x-show="shown"
                x-cloak
                class="text-green-700 dark:text-green-400"
            >
                {{ __('Saved.') }}
            </flux:text>
            <flux:button type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
