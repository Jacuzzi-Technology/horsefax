<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //

    /** A signed-in visitor with no connection to the horse: sees the full public record (signed-out visitors see a summary). */
    protected function reader(): \App\Models\User
    {
        return \App\Models\User::factory()->create();
    }
}
