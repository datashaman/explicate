<?php

namespace App\Actions\Repositories;

use App\Models\User;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;

class ListGitHubRepositories
{
    public function __construct(private Http $http) {}

    /**
     * @return list<array{full_name: string, clone_url: string, default_branch: string, private: bool}>
     */
    public function handle(User $user): array
    {
        if (! $user->github_token) {
            return [];
        }

        $repositories = $this->http
            ->withToken($user->github_token)
            ->accept('application/vnd.github+json')
            ->withHeader('X-GitHub-Api-Version', '2022-11-28')
            ->timeout(10)
            ->get('https://api.github.com/user/repos', [
                'visibility' => 'all',
                'affiliation' => 'owner,collaborator,organization_member',
                'sort' => 'full_name',
                'direction' => 'asc',
                'per_page' => 100,
            ])
            ->throw()
            ->collect();

        return $repositories
            ->filter(fn (mixed $repository): bool => is_array($repository) && filled(Arr::get($repository, 'full_name')) && filled(Arr::get($repository, 'clone_url')))
            ->map(fn (array $repository): array => [
                'full_name' => (string) $repository['full_name'],
                'clone_url' => (string) $repository['clone_url'],
                'default_branch' => (string) ($repository['default_branch'] ?? 'main'),
                'private' => (bool) ($repository['private'] ?? false),
            ])
            ->values()
            ->all();
    }
}
