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

        $content = $response->json();

        $this->assertCount(3, $content);

        $this->assertEqualsCanonicalizing(
            $clients->pluck('email')->all(),
            array_column($content, 'email'),
        );

        foreach ($clients as $client) {
            $response->assertJsonFragment(['email' => $client->email]);
        }
    }

    public function test_filters_clients_by_email(): void
    {
        $match = Client::factory()->create(['email' => 'billing@acme.test']);
        $other = Client::factory()->create(['email' => 'ops@globex.test']);

        $response = $this->getJson(route('clients.index', ['text_search' => 'acme.test']));

        $response->assertOk();

        $this->assertSame(
            [$match->email],
            array_column($response->json(), 'email'),
        );

        $response->assertJsonMissing(['email' => $other->email]);
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
