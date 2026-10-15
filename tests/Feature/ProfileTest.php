<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const WEBP_IMAGE = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v02aAA=';

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.delete-user-form');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_navigation_reflects_profile_changes_after_profile_update_event(): void
    {
        $user = User::factory()->create([
            'name' => 'Previous Name',
            'profile_photo_url' => 'https://example.com/previous-photo.webp',
        ]);

        $this->actingAs($user);

        $navigation = Volt::test('layout.navigation')
            ->assertSee('Previous Name')
            ->assertSee('previous-photo.webp');

        $user->update([
            'name' => 'Updated Name',
            'profile_photo_url' => 'https://example.com/updated-photo.webp',
        ]);

        $navigation
            ->dispatch('profile-updated', name: 'Updated Name', photoUrl: 'https://example.com/updated-photo.webp')
            ->assertSee('Updated Name')
            ->assertSee('updated-photo.webp')
            ->assertDontSee('Previous Name')
            ->assertDontSee('previous-photo.webp');
    }

    public function test_current_profile_photo_is_rendered_in_the_avatar_upload_component(): void
    {
        Storage::fake('s3');

        $profilePhotoPath = 'profile-photos/current-photo.webp';
        $user = User::factory()->create(['profile_photo_url' => $profilePhotoPath]);

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->assertSee($profilePhotoPath, false);
    }

    public function test_profile_photo_can_be_uploaded_and_replaces_the_previous_managed_photo(): void
    {
        Storage::fake('s3');

        $previousPhotoPath = 'profile-photos/previous-photo.webp';
        $user = User::factory()->create([
            'profile_photo_url' => $previousPhotoPath,
        ]);
        Storage::disk('s3')->put($previousPhotoPath, 'previous photo');

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('photo', $this->fakeWebpProfilePhoto())
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('photo', null);

        $user->refresh();
        $storedPhotos = Storage::disk('s3')->allFiles('profile-photos');

        $this->assertCount(1, $storedPhotos);
        $this->assertSame($storedPhotos[0], $user->getRawOriginal('profile_photo_url'));
        $this->assertStringContainsString('expiration=', $user->profile_photo_url);
        Storage::disk('s3')->assertMissing($previousPhotoPath);
        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'));
    }

    public function test_non_image_profile_photo_is_rejected_without_changing_the_current_photo(): void
    {
        Storage::fake('s3');

        $profilePhotoUrl = 'https://example.com/profile.jpg';
        $user = User::factory()->create(['profile_photo_url' => $profilePhotoUrl]);

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('photo', UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'))
            ->call('updateProfileInformation');

        $component
            ->assertHasErrors('photo')
            ->assertNoRedirect();

        $this->assertSame($profilePhotoUrl, $user->refresh()->profile_photo_url);
        $this->assertSame([], Storage::disk('s3')->allFiles('profile-photos'));
    }

    public function test_profile_photo_larger_than_ten_megabytes_is_rejected(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('photo', $this->fakeWebpProfilePhoto(10 * 1024 * 1024))
            ->call('updateProfileInformation');

        $component
            ->assertHasErrors('photo')
            ->assertNoRedirect();

        $this->assertSame([], Storage::disk('s3')->allFiles('profile-photos'));
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
    }

    private function fakeWebpProfilePhoto(int $paddingBytes = 0): UploadedFile
    {
        $image = base64_decode(self::WEBP_IMAGE, true);

        if ($image === false) {
            throw new \RuntimeException('The WebP test image could not be decoded.');
        }

        return UploadedFile::fake()->createWithContent(
            'profile.webp',
            $image.str_repeat('a', $paddingBytes),
        );
    }
}
