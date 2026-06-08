<?php

use App\Mcp\Servers\ExplicateServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/explicate', ExplicateServer::class)
    ->middleware(['auth:api']);

Mcp::local('explicate', ExplicateServer::class);
