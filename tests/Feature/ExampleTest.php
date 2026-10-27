<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** The dashboard is behind authentication, so a guest is sent to the login page. */
    public function test_root_redirects_guests_to_login()
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
