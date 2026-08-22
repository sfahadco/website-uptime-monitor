<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Repository\ClientRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Clients returned per page when the caller does not ask for a size.
     */
    private const int DEFAULT_PER_PAGE = 50;

    /**
     * Max size per page
     */
    private const int MAX_PER_PAGE = 100;

    public function __construct(private readonly ClientRepository $clientRepository) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $clients = $this->clientRepository->paginate(
            $validated['search'] ?? null,
            ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'data' => $clients->items(),
            'meta' => [
                'total' => $clients->total(),
                'per_page' => $clients->perPage(),
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
            ],
        ]);
    }

    public function show(Client $client): JsonResponse
    {
        $websites = $this->clientRepository->getWebsites($client);

        return response()->json($websites);
    }
}
