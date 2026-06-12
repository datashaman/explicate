<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('List briefs for an accessible workspace.')]
class WorkspaceBriefsResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::WorkspaceBriefsTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $workspace = $this->context->workspaceFor($user, (string) $request->get('workspace'));

            $briefs = $workspace->briefs()
                ->with(['workspace', 'sourceThread.workspace', 'plan.tasks'])
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn ($brief) => $this->briefSummaryPayload($brief))
                ->values()
                ->all();

            return Response::json([
                'workspace' => $workspace->only(['id', 'name', 'slug']),
                'briefs' => $briefs,
            ]);
        });
    }
}
