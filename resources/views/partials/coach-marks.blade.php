<div
    x-data="{
        show: @js($showCoachMarks),
        step: 0,
        highlightRect: null,
        cardStyle: {},
        steps: [
            {
                target: '[data-coach=\'topics\']',
                title: 'Topics organise your work',
                body: 'Topics are like channels — each one has its own feed of posts. Click a topic to open its feed and start writing.',
            },
            {
                target: '[data-coach=\'agents\']',
                title: 'AI agents generate content',
                body: 'Agents read your posts and generate content on demand. Click an agent after selecting a topic to run it.',
            },
            {
                target: '[data-coach=\'files\']',
                title: 'Add context with files',
                body: 'Upload specs, notes, or reference material here. Agents use these as extra context when generating content. Repositories can also be connected below.',
            },
        ],
        get current() { return this.steps[this.step]; },
        get isLast() { return this.step === this.steps.length - 1; },
        get highlightStyle() {
            if (!this.highlightRect) { return ''; }
            return 'top:' + this.highlightRect.top + 'px;left:' + this.highlightRect.left + 'px;width:' + this.highlightRect.width + 'px;height:' + this.highlightRect.height + 'px;';
        },
        init() {
            if (this.show) { this.$nextTick(() => this.updatePosition()); }
        },
        updatePosition() {
            const el = document.querySelector(this.current.target);
            if (!el) { return; }
            const r = el.getBoundingClientRect();
            this.highlightRect = { top: r.top, left: r.left, width: r.width, height: r.height };
            const cardWidth = 288;
            const cardHeight = 210;
            const gap = 16;
            let top = Math.max(8, r.top);
            let left = r.right + gap;
            if (left + cardWidth > window.innerWidth - 8) { left = r.left - cardWidth - gap; }
            if (top + cardHeight > window.innerHeight - 8) { top = window.innerHeight - cardHeight - 8; }
            this.cardStyle = { top: top + 'px', left: left + 'px' };
        },
        next() {
            if (this.isLast) {
                const suggestion = 'I want to build a daily habit tracker that helps people stay consistent. Break it into a product spec.';
                $wire.call('finishCoachMarks', suggestion);
                this.show = false;
            } else {
                this.step++;
                this.$nextTick(() => this.updatePosition());
            }
        },
        prev() {
            if (this.step > 0) {
                this.step--;
                this.$nextTick(() => this.updatePosition());
            }
        },
        dismiss() {
            this.show = false;
            $wire.call('dismissCoachMarks');
        },
    }"
    x-show="show"
    x-cloak
    class="pointer-events-none fixed inset-0 z-50 hidden xl:block"
>
    {{-- Backdrop --}}
    <div class="pointer-events-auto absolute inset-0 bg-black/50"></div>

    {{-- Highlight ring --}}
    <div
        x-show="highlightRect"
        :style="highlightStyle"
        class="pointer-events-none absolute z-10 rounded-lg ring-2 ring-white/80 ring-offset-1 ring-offset-transparent"
    ></div>

    {{-- Tooltip card --}}
    <div
        :style="cardStyle"
        class="pointer-events-auto absolute z-20 w-72 rounded-xl bg-white p-5 shadow-2xl dark:bg-zinc-800"
    >
        <div class="mb-1 flex items-start justify-between gap-3">
            <p class="font-semibold text-zinc-900 dark:text-white" x-text="current.title"></p>
            <span class="mt-0.5 shrink-0 text-xs text-zinc-400" x-text="(step + 1) + ' / ' + steps.length"></span>
        </div>
        <p class="mb-5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400" x-text="current.body"></p>
        <div class="flex items-center justify-between">
            <button
                x-show="!isLast"
                @click="dismiss"
                class="text-sm text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
            >{{ __('Skip tour') }}</button>
            <div class="flex gap-2" :class="isLast ? 'ml-auto w-full justify-between' : 'ml-auto'">
                <button
                    x-show="step > 0"
                    @click="prev"
                    class="rounded-lg bg-zinc-100 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600"
                >{{ __('Back') }}</button>
                <button
                    x-show="isLast"
                    @click="dismiss"
                    class="text-sm text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
                >{{ __('Skip') }}</button>
                <button
                    @click="next"
                    class="rounded-lg bg-zinc-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100"
                    x-text="isLast ? '{{ __('Start writing') }}' : '{{ __('Next') }}'"
                ></button>
            </div>
        </div>
    </div>
</div>
