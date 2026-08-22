<?php

namespace App\Repository;

use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ClientRepository
{
    public function paginate(?string $search, int $perPage): LengthAwarePaginator
    {
        return Client::query()
            ->select(['id', 'email'])
            ->when(
                filled($search),
                fn ($query) => $query->where('email', 'like', '%'.$search.'%'),
            )
            ->orderBy('email')
            ->paginate($perPage);
    }

    public function getWebsites(Client $client): Collection
    {
        return $client->websites()
            ->select(['id', 'url'])
            ->orderBy('url')
            ->get();
    }
}
