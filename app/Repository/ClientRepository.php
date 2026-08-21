<?php

namespace App\Repository;

use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;

class ClientRepository
{
    public function get($payload): Collection
    {
        $query = Client::query();

        $query->when($payload['text_search'] ?? null, function ($q, $search) {
            return $q->where('email', 'like', '%' . $search . '%');
        });

        return $query->limit(10)->get();
    }

    public function getWebsites(Client $client): Collection
    {
        return $client->websites()->get();
    }
}
