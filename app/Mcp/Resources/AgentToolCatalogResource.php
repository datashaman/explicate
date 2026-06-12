<?php

namespace App\Mcp\Resources;

use App\Actions\Agents\AgentToolCatalog;
use App\Mcp\ExplicateUris;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('List the MCP tools that may be assigned to agent allowed_tools lists.')]
#[Uri(ExplicateUris::AgentToolCatalog)]
#[MimeType('application/json')]
class AgentToolCatalogResource extends Resource
{
    public function __construct(private readonly AgentToolCatalog $catalog) {}

    public function handle(Request $request): Response
    {
        return Response::json([
            'resource_uri' => ExplicateUris::AgentToolCatalog,
            'allowed_tools' => $this->catalog->names(),
            'groups' => $this->catalog->grouped(),
        ]);
    }
}
