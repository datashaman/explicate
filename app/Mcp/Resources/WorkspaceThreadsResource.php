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

#[Description('List threads for an accessible workspace.')]
class WorkspaceThreadsResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::WorkspaceThreadsTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $workspace = $this->context->workspaceFor($user, (string) $request->get('workspace'));

            $threads = $workspace->threads()
                ->whereHas('posts')
                ->with(['workspace', 'topic', 'latestPost.sender.user', 'latestPost.sender.agent'])
                ->withCount('posts')
                ->get()
                ->map(fn ($thread) => $this->threadSummaryPayload($thread))
                ->values()
                ->all();

            return Response::json([
                'workspace' => $workspace->only(['id', 'name', 'slug']),
                'threads' => $threads,
            ]);
        });
    }
}
