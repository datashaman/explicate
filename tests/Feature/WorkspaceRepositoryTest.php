<?php

use App\Models\WorkspaceRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

afterEach(function () {
    $reposDir = storage_path('app/workspace-repos');
    if (is_dir($reposDir)) {
        exec("rm -rf {$reposDir}");
    }
});

test('repositories panel renders repo list', function () {
    [$user, $workspace] = userWithWorkspace();
    WorkspaceRepository::factory()->for($workspace)->create(['name' => 'my-repo']);

    $this->actingAs($user)
        ->get(route('dashboard', ['action' => 'repos', 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('my-repo');
});

test('repository count badge reflects number of repos', function () {
    [$user, $workspace] = userWithWorkspace();
    WorkspaceRepository::factory()->count(3)->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['action' => 'repos', 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="workspace-repos-count"', false)
        ->assertSeeText('3');
});

test('createRepository validates required fields', function () {
    [$user, $workspace] = userWithWorkspace();

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'repos'])
        ->set('repositoryName', '')
        ->set('repositoryUrl', '')
        ->call('createRepository')
        ->assertHasErrors(['repositoryName', 'repositoryUrl']);
});

test('repositories panel shows github repository dropdown for connected users', function () {
    [$user] = userWithWorkspace(['github_token' => 'gho-secret-token']);

    Http::fake([
        'api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'datashaman/explicate',
                'clone_url' => 'https://github.com/datashaman/explicate.git',
                'default_branch' => 'main',
                'private' => true,
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['action' => 'repos', 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="github-repository-select"', false)
        ->assertSee('datashaman/explicate')
        ->assertSee('private');
});

test('selecting a github repository fills repository fields without exposing token', function () {
    [$user] = userWithWorkspace(['github_token' => 'gho-secret-token']);

    Http::fake([
        'api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'datashaman/explicate',
                'clone_url' => 'https://github.com/datashaman/explicate.git',
                'default_branch' => 'develop',
                'private' => false,
            ],
        ]),
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'repos'])
        ->set('repositoryProvider', 'github')
        ->set('selectedGitHubRepository', 'datashaman/explicate')
        ->assertSet('repositoryName', 'datashaman/explicate')
        ->assertSet('repositoryUrl', 'https://github.com/datashaman/explicate.git')
        ->assertSet('repositoryBranch', 'develop')
        ->assertSet('repositoryAuthType', 'token')
        ->assertSet('repositoryAccessToken', '');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer gho-secret-token')
        && str_starts_with($request->url(), 'https://api.github.com/user/repos'));
});

test('createRepository can use connected github token without public token field', function () {
    [$user, $workspace] = userWithWorkspace(['github_token' => 'gho-secret-token']);

    Http::fake([
        'api.github.com/user/repos*' => Http::response([]),
    ]);

    $bare = sys_get_temp_dir().'/bare-'.uniqid();
    exec("git init --bare {$bare} 2>&1");
    $tmp = sys_get_temp_dir().'/clone-'.uniqid();
    exec("git clone {$bare} {$tmp} 2>&1");
    file_put_contents("{$tmp}/README.md", '# x');
    exec("git -C {$tmp} config user.email test@example.com && git -C {$tmp} config user.name T && git -C {$tmp} add . && git -C {$tmp} commit -m init && git -C {$tmp} push origin HEAD:main 2>&1");
    exec("rm -rf {$tmp}");

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'repos'])
        ->set('repositoryProvider', 'github')
        ->set('repositoryName', 'datashaman/explicate')
        ->set('repositoryUrl', $bare)
        ->set('repositoryBranch', 'main')
        ->set('repositoryAuthType', 'token')
        ->set('repositoryAccessToken', '')
        ->call('createRepository')
        ->assertHasNoErrors();

    $repo = $workspace->repositories()->where('name', 'datashaman/explicate')->sole();

    expect($repo->access_token)->toBe('gho-secret-token');

    exec("rm -rf {$bare}");
});

test('createRepository saves repo when credentials validate', function () {
    [$user, $workspace] = userWithWorkspace();

    $bare = sys_get_temp_dir().'/bare-'.uniqid();
    exec("git init --bare {$bare} 2>&1");
    $tmp = sys_get_temp_dir().'/clone-'.uniqid();
    exec("git clone {$bare} {$tmp} 2>&1");
    file_put_contents("{$tmp}/README.md", '# x');
    exec("git -C {$tmp} config user.email test@example.com && git -C {$tmp} config user.name T && git -C {$tmp} add . && git -C {$tmp} commit -m init && git -C {$tmp} push origin HEAD:main 2>&1");
    exec("rm -rf {$tmp}");

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'repos'])
        ->set('repositoryName', 'test-repo')
        ->set('repositoryUrl', $bare)
        ->set('repositoryBranch', 'main')
        ->set('repositoryAuthType', 'ssh')
        ->set('repositorySshPrivateKey', '---fake---')
        ->call('createRepository')
        ->assertHasNoErrors();

    expect($workspace->repositories()->where('name', 'test-repo')->exists())->toBeTrue();

    exec("rm -rf {$bare}");
});

test('createRepository shows error when repo url is not accessible', function () {
    [$user, $workspace] = userWithWorkspace();

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'repos'])
        ->set('repositoryName', 'bad-repo')
        ->set('repositoryUrl', 'git@github.com:nonexistent/repo-'.uniqid().'.git')
        ->set('repositoryBranch', 'main')
        ->set('repositoryAuthType', 'ssh')
        ->set('repositorySshPrivateKey', 'invalid-key')
        ->call('createRepository')
        ->assertHasErrors(['repositoryUrl']);

    expect($workspace->repositories()->where('name', 'bad-repo')->exists())->toBeFalse();
});

test('deleteRepository removes the model from the database', function () {
    [$user, $workspace] = userWithWorkspace();
    $repo = WorkspaceRepository::factory()->for($workspace)->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'repos'])
        ->call('deleteRepository', $repo->id)
        ->assertHasNoErrors();

    expect(WorkspaceRepository::find($repo->id))->toBeNull();
});

test('deleteRepository removes on-disk clone if present', function () {
    [$user, $workspace] = userWithWorkspace();
    $repo = WorkspaceRepository::factory()->for($workspace)->create();

    $localPath = $repo->localPath();
    mkdir($localPath.'/.git', 0755, true);
    file_put_contents($localPath.'/README.md', '# test');

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'repos'])
        ->call('deleteRepository', $repo->id);

    expect(is_dir($localPath))->toBeFalse();
});

test('ssh credentials are stored encrypted', function () {
    $repo = WorkspaceRepository::factory()->create([
        'auth_type' => 'ssh',
        'ssh_private_key' => 'super-secret-key',
    ]);

    $raw = DB::table('workspace_repositories')
        ->where('id', $repo->id)
        ->value('ssh_private_key');

    expect($raw)->not->toBe('super-secret-key');
    expect($repo->fresh()->ssh_private_key)->toBe('super-secret-key');
});
