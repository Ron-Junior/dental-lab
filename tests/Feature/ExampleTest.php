<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    protected bool $usesTenantDatabase = false;

    public function test_the_central_homepage_redirects_to_registration(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('onboarding.create'));
    }
}
