<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_policy_and_terms_are_public_inertia_pages(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Legal')
                ->where('documentType', 'privacy')
                ->has('legalContent')
            );

        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Legal')
                ->where('documentType', 'terms')
                ->has('legalContent')
            );
    }
}
