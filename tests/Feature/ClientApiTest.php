<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_client_emails(): void
    {
        $clients = Client::factory()->count(3)->create();

        $response = $this->getJson('/api/clients');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'email']],
            ]);

        foreach ($clients as $client) {
            $response->assertJsonFragment(['email' => $client->email]);
        }
    }

    public function test_returns_only_the_requested_clients_websites(): void
    {
        $client = Client::factory()
            ->has(Website::factory()->count(3))
            ->create();

        $other = Client::factory()
            ->has(Website::factory()->count(2))
            ->create();

        $response = $this->getJson("/api/clients/{$client->id}/websites");

        $response->assertOk()->assertJsonCount(3, 'data');

        $returned = collect($response->json('data'))->pluck('url');

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
        $id = Client::factory()->create()->id + 1;

        $this->getJson("/api/clients/{$id}/websites")->assertNotFound();
    }
}
