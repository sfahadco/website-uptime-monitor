<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_client_emails(): void
    {
        $clients = Client::factory()->count(3)->create();

        $response = $this->getJson(route('clients.index'));

        $response->assertOk();

        $content = $response->json('data');

        $this->assertCount(3, $content);

        $this->assertEqualsCanonicalizing(
            $clients->pluck('email')->all(),
            array_column($content, 'email'),
        );

        foreach ($clients as $client) {
            $response->assertJsonFragment(['email' => $client->email]);
        }
    }

    public function test_it_caps_a_page_and_reports_the_full_total(): void
    {
        Client::factory()->count(120)->create();

        $response = $this->getJson(route('clients.index'));

        $response->assertOk();

        // The page is bounded, but the client still learns how many rows exist
        // so it can tell the user the list is not complete.
        $this->assertCount(50, $response->json('data'));
        $this->assertSame(120, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    public function test_it_pages_through_every_client_exactly_once(): void
    {
        $expected = Client::factory()->count(120)->create()->pluck('email')->all();

        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson(route('clients.index', ['page' => $page]));

            $response->assertOk();

            $seen = array_merge($seen, array_column($response->json('data'), 'email'));
        }

        $this->assertEqualsCanonicalizing($expected, $seen);
    }

    public function test_it_honours_a_requested_page_size(): void
    {
        Client::factory()->count(10)->create();

        $response = $this->getJson(route('clients.index', ['per_page' => 4]));

        $response->assertOk();

        $this->assertCount(4, $response->json('data'));
        $this->assertSame(4, $response->json('meta.per_page'));
    }

    public function test_it_rejects_a_page_size_above_the_ceiling(): void
    {
        $this->getJson(route('clients.index', ['per_page' => 500]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_filters_clients_by_an_email_substring(): void
    {
        Client::factory()->create(['email' => 'alice@acme.test']);
        Client::factory()->create(['email' => 'bob@acme.test']);
        Client::factory()->create(['email' => 'alice@other.test']);

        $response = $this->getJson(route('clients.index', ['search' => 'alice']));

        $response->assertOk();

        $this->assertSame(2, $response->json('meta.total'));

        $this->assertEqualsCanonicalizing(
            ['alice@acme.test', 'alice@other.test'],
            array_column($response->json('data'), 'email'),
        );
    }

    public function test_returns_only_the_requested_clients_websites(): void
    {
        $client = Client::factory()
            ->has(Website::factory()->count(3))
            ->create();

        $other = Client::factory()
            ->has(Website::factory()->count(2))
            ->create();

        $response = $this->getJson(route('clients.show', ['client' => $client]));

        $response->assertOk();

        $content = $response->json();

        $this->assertCount(3, $content);

        $returned = collect($content)->pluck('url');

        $this->assertEqualsCanonicalizing(
            $client->websites->pluck('url')->all(),
            $returned->all(),
        );

        foreach ($other->websites as $website) {
            $response->assertJsonMissing(['url' => $website->url]);
        }
    }

    public function test_returns_404_for_an_unknown_client(): void
    {
        $response = $this->getJson(route('clients.show', ['client' => 999]));

        $response->assertNotFound();
    }
}
