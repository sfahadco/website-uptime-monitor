<?php

use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;

class ClientRepository
{
    public function get($payload): Collection
    {
        $query = Client::query();

        $query->when($payload['text_search'], function ($q, $search) {
            return $q->where('name', 'like', '%' . $search . '%');
        });

        return $query->limit(10)->get();
    }

    public function findByClient(Client $client): Client
    {
         return $client->load('websites');
    }
}
