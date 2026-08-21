<?php

namespace App\Http\Controllers;

use App\Models\Client;
use ClientRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(private readonly ClientRepository $clientRepository)
    {

    }
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text_search' => ['nullable', 'string', 'max:255'],
        ]);

        $clients = $this->clientRepository->get($validated);

        return response()->json($clients);
    }

    public function show(Client $client): JsonResponse
    {
        $clientData = $this->clientRepository->findByClient($client);

        return response()->json($clientData);
    }
}
