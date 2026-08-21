<?php

namespace App\Repository;

use App\Models\Client;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;

class ClientRepository
{
    private const int SEARCH_LIMIT = 10;

    public function get(array $payload): Collection
    {
        return Client::query()
            ->select(['id', 'email'])
            ->when($payload['text_search'] ?? null, function (Builder $query, string $search) {
                $query->where('email', 'like', '%' . $search . '%');
            })
            ->limit(self::SEARCH_LIMIT)
            ->get();
    }

    public function getWebsites(Client $client): Collection
    {
        return $client->websites()
            ->select(['id', 'url'])
            ->get();
    }
}
