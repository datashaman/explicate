<?php

use App\Models\WorkspaceRepository;
use App\Services\GitRepositoryService;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

function makeBareRepo(): string
{
    $path = sys_get_temp_dir().'/test-bare-repo-'.uniqid();
    mkdir($path, 0755, true);
    exec("git init --bare {$path} 2>&1");
    exec("git -C {$path} symbolic-ref HEAD refs/heads/main 2>&1");

    $tmp = sys_get_temp_dir().'/test-clone-'.uniqid();
    exec("git clone {$path} {$tmp} 2>&1");
    file_put_contents("{$tmp}/README.md", '# Test');
    exec("git -C {$tmp} config user.email test@example.com 2>&1");
    exec("git -C {$tmp} config user.name Test 2>&1");
    exec("git -C {$tmp} add README.md 2>&1");
    exec("git -C {$tmp} commit -m 'init' 2>&1");
    exec("git -C {$tmp} push origin HEAD:main 2>&1");
    exec("rm -rf {$tmp}");

    return $path;
}

function makeUnsavedRepo(string $url): WorkspaceRepository
{
    $repo = new WorkspaceRepository;
    $repo->workspace_id = 999;
    $repo->id = 1;
    $repo->name = 'test-repo';
    $repo->url = $url;
    $repo->branch = 'main';
    $repo->auth_type = 'ssh';
    $repo->ssh_private_key = null;

    return $repo;
}

function makeUnsavedTokenRepo(string $url, string $token = 'gho-secret-token'): WorkspaceRepository
{
    $repo = makeUnsavedRepo($url);
    $repo->auth_type = 'token';
    $repo->ssh_private_key = null;
    $repo->access_token = $token;

    return $repo;
}

afterEach(function () {
    $reposDir = storage_path('app/workspace-repos');
    if (is_dir($reposDir)) {
        exec("rm -rf {$reposDir}");
    }
});

test('clone downloads the repository to localPath', function () {
    $bare = makeBareRepo();
    $repo = makeUnsavedRepo($bare);

    (new GitRepositoryService($repo))->sync();

    expect($repo->isCloned())->toBeTrue();
    expect(file_exists($repo->localPath().'/README.md'))->toBeTrue();

    exec("rm -rf {$bare}");
});

test('sync pulls new commits when repo is already cloned', function () {
    $bare = makeBareRepo();
    $repo = makeUnsavedRepo($bare);

    $service = new GitRepositoryService($repo);
    $service->sync();

    $tmp = sys_get_temp_dir().'/test-push-'.uniqid();
    exec("git clone --branch main {$bare} {$tmp} 2>&1");
    file_put_contents("{$tmp}/new-file.md", 'New content');
    exec("git -C {$tmp} config user.email test@example.com 2>&1");
    exec("git -C {$tmp} config user.name Test 2>&1");
    exec("git -C {$tmp} add new-file.md 2>&1");
    exec("git -C {$tmp} commit -m 'add new file' 2>&1");
    exec("git -C {$tmp} push origin HEAD:main 2>&1");
    exec("rm -rf {$tmp}");

    $service->sync();

    expect(file_exists($repo->localPath().'/new-file.md'))->toBeTrue();

    exec("rm -rf {$bare}");
});

test('remove deletes the local directory', function () {
    $bare = makeBareRepo();
    $repo = makeUnsavedRepo($bare);

    $service = new GitRepositoryService($repo);
    $service->sync();
    $service->remove();

    expect(is_dir($repo->localPath()))->toBeFalse();

    exec("rm -rf {$bare}");
});

test('validate throws when repo url is not accessible', function () {
    $repo = makeUnsavedRepo('/non/existent/path.git');

    expect(fn () => (new GitRepositoryService($repo))->validate())->toThrow(RuntimeException::class);
});

test('run executes a command in the repo directory and returns output', function () {
    $bare = makeBareRepo();
    $repo = makeUnsavedRepo($bare);

    $service = new GitRepositoryService($repo);
    $service->sync();

    $result = $service->run(['git', 'log', '--oneline', '-1']);

    expect($result['exit_code'])->toBe(0);
    expect($result['stdout'])->toContain('init');

    exec("rm -rf {$bare}");
});

test('token auth is exposed to git as a basic auth header', function () {
    $bare = makeBareRepo();
    $repo = makeUnsavedTokenRepo($bare, 'gho-secret-token');

    $service = new GitRepositoryService($repo);
    $service->sync();

    $result = $service->run(['git', 'config', '--get', 'http.extraHeader']);

    expect($result['exit_code'])->toBe(0);
    expect(trim($result['stdout']))->toBe('Authorization: Basic '.base64_encode('x-access-token:gho-secret-token'));

    exec("rm -rf {$bare}");
});
