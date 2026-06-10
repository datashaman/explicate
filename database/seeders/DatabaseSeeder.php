<?php

namespace Database\Seeders;

use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\Thread;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $team = $user->currentTeam;

        $workspace = Workspace::factory()->for($team)->create(['name' => 'My First Workspace']);
        $user->switchWorkspace($workspace);
        $senderPrincipal = $workspace->principalForUser($user);

        $topics = Topic::factory()->for($workspace)->createMany([
            ['name' => 'Design'],
            ['name' => 'Engineering'],
            ['name' => 'Marketing'],
            ['name' => 'Research'],
        ]);

        $agents = collect([
            [
                'name' => 'Writer',
                'provider' => Provider::OpenAI,
                'model' => 'o4-mini',
                'reasoning_effort' => ReasoningEffort::Medium,
                'prompt' => 'Write concise, high-signal drafts and tighten structure.',
            ],
            [
                'name' => 'Researcher',
                'provider' => Provider::Anthropic,
                'model' => 'claude-sonnet-4-6',
                'reasoning_effort' => null,
                'prompt' => 'Synthesize sources, note uncertainty, and surface edge cases.',
            ],
            [
                'name' => 'SEO Analyst',
                'provider' => Provider::Gemini,
                'model' => 'gemini-2.5-pro',
                'reasoning_effort' => null,
                'prompt' => 'Optimize content structure, discover keyword clusters, and flag gaps.',
            ],
            [
                'name' => 'Reviewer',
                'provider' => Provider::Groq,
                'model' => 'llama-3.3-70b-versatile',
                'reasoning_effort' => null,
                'prompt' => 'Review content for consistency, clarity, and missing assumptions.',
            ],
        ])->map(function (array $seededAgent) use ($workspace): Agent {
            $agent = $workspace->agents()->create([
                'name' => $seededAgent['name'],
                'slug' => Str::slug($seededAgent['name']),
            ]);

            $agent->versions()->create([
                'version' => 1,
                'provider' => $seededAgent['provider'],
                'model' => $seededAgent['model'],
                'reasoning_effort' => $seededAgent['reasoning_effort'],
                'prompt' => $seededAgent['prompt'],
                'created_at' => now(),
            ]);

            return $agent->fresh(['latestVersion']);
        });

        $topicsByName = $topics->keyBy('name');

        $designDraftThread = Thread::factory()->forTopic($topicsByName['Design'])->create([
            'title' => 'Homepage hero directions',
        ]);

        $designDraft = Post::factory()->for($designDraftThread)->create([
            'body' => "Homepage hero directions\n\nExplore three tonal directions for the homepage hero.\n\n1. Product-led\n2. Proof-led\n3. Narrative-led",
            'status' => PostStatus::Draft,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        $this->createSeedAttachment(
            post: $designDraft,
            filename: 'hero-directions.txt',
            contents: "Product-led\nProof-led\nNarrative-led\n",
            mimeType: 'text/plain',
        );
        $this->createSeedAttachment(
            post: $designDraft,
            filename: 'hero-copy-notes.txt',
            contents: "Keep the first viewport focused on the product and next action.\n",
            mimeType: 'text/plain',
        );

        $designPublishedThread = Thread::factory()->forTopic($topicsByName['Design'])->create([
            'title' => 'Reviewer brief: Brand voice notes',
        ]);

        $designPublishedPost = Post::factory()->for($designPublishedThread)->create([
            'body' => "Reviewer brief: Brand voice notes\n\nCapture phrases to avoid and preferred tone examples.",
            'status' => PostStatus::Published,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        Post::factory()->for($designPublishedThread)->create([
            'body' => "I'll compare this against the homepage draft and propose a tighter phrasing list.",
            'status' => PostStatus::Published,
            'sender_principal_id' => $workspace->principalForAgent($agents->firstWhere('name', 'Reviewer'))->id,
        ]);

        $this->createSeedAttachment(
            post: $designPublishedPost,
            filename: 'brand-snapshot.svg',
            contents: $this->seedImageContents(),
            mimeType: 'image/svg+xml',
        );

        $engineeringDraftThread = Thread::factory()->forTopic($topicsByName['Engineering'])->create([
            'title' => 'Agent orchestration outline',
        ]);

        Post::factory()->for($engineeringDraftThread)->create([
            'body' => "Agent orchestration outline\n\nDocument the post lifecycle and queue boundaries.\n\nInclude failure handling and retry policy.",
            'status' => PostStatus::Draft,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        $deletedThread = Thread::factory()->forTopic($topicsByName['Engineering'])->create([
            'title' => 'Model fallback strategy',
        ]);

        Post::factory()->for($deletedThread)->create([
            'body' => "Model fallback strategy\n\nCompare provider fallback order and expected quality tradeoffs.",
            'status' => PostStatus::Published,
            'sender_principal_id' => $senderPrincipal->id,
            'deleted_by_user_id' => $user->id,
            'deleted_at' => now()->subMinutes(20),
        ]);

        $marketingThread = Thread::factory()->forTopic($topicsByName['Marketing'])->create([
            'title' => 'SEO analyst brief: Q3 campaign angles',
        ]);

        Post::factory()->for($marketingThread)->create([
            'body' => "SEO analyst brief: Q3 campaign angles\n\nList campaign themes tied to the strongest product outcomes.",
            'status' => PostStatus::Published,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        Post::factory()->for($marketingThread)->create([
            'body' => 'Prioritize themes with clear activation or retention proof.',
            'status' => PostStatus::Published,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        $marketingDraftThread = Thread::factory()->forTopic($topicsByName['Marketing'])->create([
            'title' => 'Landing page test ideas',
        ]);

        Post::factory()->for($marketingDraftThread)->create([
            'body' => "Landing page test ideas\n\nPropose headline, CTA, and proof-module experiments.",
            'status' => PostStatus::Draft,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        $researchThread = Thread::factory()->forTopic($topicsByName['Research'])->create([
            'title' => 'Competitor workflow notes',
        ]);

        Post::factory()->for($researchThread)->create([
            'body' => "Competitor workflow notes\n\nTrack workflow patterns from adjacent tools and notable gaps.",
            'status' => PostStatus::Draft,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        $topiclessThread = Thread::factory()->for($workspace)->create([
            'title' => 'Inbox triage notes',
            'topic_id' => null,
        ]);

        Post::factory()->for($topiclessThread)->create([
            'body' => "Inbox triage notes\n\nSort these into topics once the next planning pass is complete.",
            'status' => PostStatus::Published,
            'sender_principal_id' => $senderPrincipal->id,
        ]);
    }

    private function createSeedAttachment(Post $post, string $filename, string $contents, string $mimeType): Attachment
    {
        $path = "attachments/seed/{$post->ulid}/{$filename}";

        $post->thread->workspace->filesystem()->write($path, $contents);

        return $post->attachments()->create([
            'filename' => $filename,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => strlen($contents),
        ]);
    }

    private function seedImageContents(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360" viewBox="0 0 640 360">
  <rect width="640" height="360" fill="#f8fafc"/>
  <rect x="48" y="48" width="544" height="264" rx="24" fill="#ecfdf5" stroke="#10b981" stroke-width="4"/>
  <circle cx="142" cy="132" r="42" fill="#f59e0b"/>
  <rect x="216" y="104" width="292" height="28" rx="14" fill="#111827"/>
  <rect x="216" y="152" width="356" height="20" rx="10" fill="#6b7280"/>
  <rect x="216" y="192" width="240" height="20" rx="10" fill="#9ca3af"/>
  <rect x="88" y="244" width="464" height="32" rx="16" fill="#10b981"/>
</svg>
SVG;
    }
}
