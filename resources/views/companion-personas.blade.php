<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
            <span class="gradient-text">⚙️ 伴读人格库</span>
        </h2>
    </x-slot>

    <div class="py-6" x-data="personaManager()">
        <div class="mx-auto max-w-4xl px-4">
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">自定义多套伴读人格（口吻 / 系统提示词 / 头像）。对话时可随时切换；首次已自动播种 4 套默认人格。</p>
                <button type="button" @click="openCreate()"
                    class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">＋ 新建人格</button>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <template x-for="p in personas" :key="p.id">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex items-start gap-3">
                            <div class="text-3xl" x-text="p.emoji"></div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-100" x-text="p.name"></span>
                                    <span x-show="p.is_default" class="rounded-full bg-primary-100 px-2 py-0.5 text-[10px] text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">默认</span>
                                </div>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400" x-text="p.description"></p>
                                <p class="mt-2 line-clamp-3 text-xs text-zinc-400" x-text="p.system_prompt"></p>
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button type="button" @click="openEdit(p)"
                                class="rounded-lg border border-zinc-200 px-3 py-1 text-xs font-medium text-zinc-600 hover:border-primary-400 hover:text-primary-600 dark:border-zinc-700 dark:text-zinc-300">编辑</button>
                            <button type="button" @click="remove(p)"
                                class="rounded-lg border border-zinc-200 px-3 py-1 text-xs font-medium text-rose-600 hover:border-rose-400 dark:border-zinc-700">删除</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- 新建 / 编辑 模态 -->
        <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="close()">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="text-base font-semibold text-zinc-800 dark:text-zinc-100" x-text="editing ? '编辑人格' : '新建人格'"></h3>

                <div class="mt-4 space-y-3">
                    <div class="flex gap-3">
                        <div class="w-20">
                            <label class="text-xs text-zinc-500">头像</label>
                            <input x-model="form.emoji" maxlength="4" placeholder="🤖"
                                class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-2 py-2 text-center text-xl dark:border-zinc-700 dark:bg-zinc-800">
                        </div>
                        <div class="flex-1">
                            <label class="text-xs text-zinc-500">名称</label>
                            <input x-model="form.name" maxlength="40" placeholder="例如：博学旁白"
                                class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-zinc-500">一句话简介</label>
                        <input x-model="form.description" maxlength="200" placeholder="这个人格擅长什么"
                            class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                    </div>
                    <div>
                        <label class="text-xs text-zinc-500">系统提示词（决定它的口吻与行为）</label>
                        <textarea x-model="form.system_prompt" rows="6" placeholder="你是一位……"
                            class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"></textarea>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" @click="close()"
                        class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">取消</button>
                    <button type="button" @click="save()" :disabled="saving"
                        class="rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">保存</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        function personaManager() {
            return {
                personas: [],
                modalOpen: false,
                editing: false,
                saving: false,
                form: { id: null, name: '', emoji: '🤖', description: '', system_prompt: '' },

                init() { this.load(); },

                csrf() {
                    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                },

                async load() {
                    try {
                        const resp = await fetch('/api/companion/personas', {
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        const data = await resp.json();
                        if (data.ok) this.personas = data.personas;
                    } catch (e) {}
                },

                openCreate() {
                    this.editing = false;
                    this.form = { id: null, name: '', emoji: '🤖', description: '', system_prompt: '' };
                    this.modalOpen = true;
                },

                openEdit(p) {
                    this.editing = true;
                    this.form = { id: p.id, name: p.name, emoji: p.emoji, description: p.description || '', system_prompt: p.system_prompt };
                    this.modalOpen = true;
                },

                close() { this.modalOpen = false; },

                async save() {
                    if (!this.form.name.trim() || !this.form.system_prompt.trim()) {
                        alert('名称和系统提示词都不能为空');
                        return;
                    }
                    this.saving = true;
                    try {
                        const url = this.editing ? ('/api/companion/personas/' + this.form.id) : '/api/companion/personas';
                        const method = this.editing ? 'PUT' : 'POST';
                        const resp = await fetch(url, {
                            method,
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify(this.form),
                        });
                        const data = await resp.json();
                        if (data.ok) {
                            this.modalOpen = false;
                            await this.load();
                        } else {
                            alert(data.msg || '保存失败');
                        }
                    } catch (e) {
                        alert('网络错误，请重试');
                    } finally {
                        this.saving = false;
                    }
                },

                async remove(p) {
                    if (!window.confirm('删除人格「' + p.name + '」？引用它的历史消息会保留但不再关联该人格。')) return;
                    try {
                        const resp = await fetch('/api/companion/personas/' + p.id, {
                            method: 'DELETE',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        const data = await resp.json();
                        if (data.ok) this.personas = this.personas.filter(x => x.id !== p.id);
                    } catch (e) {}
                },
            };
        }

        window.personaManager = personaManager;
        if (window.Alpine && window.Alpine.data) window.Alpine.data('personaManager', personaManager);
        document.addEventListener('alpine:init', () => {
            if (window.Alpine && window.Alpine.data) window.Alpine.data('personaManager', personaManager);
        });
    })();
    </script>
</x-app-layout>
