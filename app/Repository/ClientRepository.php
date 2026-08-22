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
                // Not escaped, so % and _ act as wildcards. Fine for an
                // internal search box; it is not an injection risk either way.
                fn ($query) => $query->where('email', 'like', '%'.$search.'%'),
            )
            // Paging only makes sense over a stable, total sort. Email is
            // unique, so it gives one on its own.
            ->orderBy('email')
            ->paginate($perPage);
    }

    /**
     * Not paginated: the brief says a client has up to ten websites, so the
     * list is short. Nothing in the schema enforces that, though.
     */
    public function getWebsites(Client $client): Collection
    {
        return $client->websites()
            ->select(['id', 'url'])
            ->orderBy('url')
            ->get();
    }
}
