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

#[Description('Read one brief from an accessible workspace.')]
class BriefResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::BriefTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $brief = $this->context->briefFor(
                $user,
                (string) $request->get('brief'),
                (string) $request->get('workspace'),
            );
            $brief->load(['workspace', 'sourceThread.workspace', 'plan.tasks']);

            return Response::json([
                'workspace' => $brief->workspace->only(['id', 'name', 'slug']),
                'brief' => $this->briefPayload($brief),
            ]);
        });
    }
}
