<?php

namespace App\Repository;

use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;

class ClientRepository
{
    public function get(): Collection
    {
        return Client::query()
            ->select(['id', 'email'])
            ->get();
    }

    public function getWebsites(Client $client): Collection
    {
        return $client->websites()
            ->select(['id', 'url'])
            ->get();
    }
}
