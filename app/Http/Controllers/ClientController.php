<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Repository\ClientRepository;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    public function __construct(private readonly ClientRepository $clientRepository)
    {

    }
    public function index(): JsonResponse
    {
        $clients = $this->clientRepository->get();

        return response()->json($clients);
    }

    public function show(Client $client): JsonResponse
    {
        $websites = $this->clientRepository->getWebsites($client);

        return response()->json($websites);
    }
}
