{{--
    Adds a contractor from a popup, so a half-filled quote form is not lost. Works like the client popup on the project form.
    The page wraps its content in x-data="contractorPopup()" (or spreads it into its own data) and includes this once inside that element.
    Each quote form's list is marked data-contractor-select: all of them gain the new contractor, and the form that asked has it chosen.
--}}
<script>
    function contractorPopup() {
        const blank = { name: '', contact_person: '', phone: '', email: '', gstin: '', address: '' };

        return {
            open: false,
            saving: false,
            failed: false,
            errors: {},
            note: {},
            contractor: { ...blank },
            listId: null,
            opener: null,
            show(listId, opener) {
                this.contractor = { ...blank };
                this.errors = {};
                this.failed = false;
                this.listId = listId;
                this.opener = opener;
                this.open = true;
                this.$nextTick(() => this.$refs.contractorName.focus());
            },
            hide() {
                if (this.open && ! this.saving) {
                    this.open = false;
                    this.$nextTick(() => this.opener?.focus());
                }
            },
            async save() {
                if (this.saving) return;

                this.saving = true;
                this.errors = {};
                this.failed = false;

                try {
                    const response = await fetch(@js(route('contractor-control.contractors.store')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ ...this.contractor, gstin: this.contractor.gstin.toUpperCase() }),
                    });

                    if (response.status === 422) {
                        this.errors = (await response.json()).errors;
                    } else if (response.ok) {
                        this.choose(await response.json());
                        this.saving = false;
                        this.hide();
                    } else {
                        this.failed = true;
                    }
                } catch {
                    this.failed = true;
                } finally {
                    this.saving = false;
                }
            },
            choose(saved) {
                document.querySelectorAll('select[data-contractor-select]').forEach((list) => {
                    if (! [...list.options].some((option) => option.value === String(saved.id))) {
                        list.add(new Option(saved.name, saved.id));
                    }

                    if (list.id === this.listId) {
                        list.value = String(saved.id);
                    }
                });

                // A name the company already has comes back as that contractor, with a line saying so.
                this.note = { [this.listId]: saved.message ?? '' };
            },
        };
    }
</script>

<div x-show="open" x-cloak class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-ink/50 p-4" role="dialog" aria-modal="true" aria-labelledby="add-contractor-title" @keydown.escape.window="hide()">
    <form class="card w-full max-w-lg" @submit.prevent="save()" novalidate>
        <div class="card-head">
            <h2 id="add-contractor-title" class="card-title">{{ __('Add contractor') }}</h2>
            <button type="button" class="p-1 text-ink-3 hover:text-ink" @click="hide()" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
        </div>
        <div class="grid gap-3 p-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="label" for="contractor-name">{{ __('Name') }}</label>
                <input id="contractor-name" x-ref="contractorName" x-model="contractor.name" class="input" maxlength="160" autocomplete="organization">
                <p class="error" x-show="errors.name" x-text="errors.name?.[0]"></p>
            </div>
            <div>
                <label class="label" for="contractor-contact">{{ __('Contact person') }}</label>
                <input id="contractor-contact" x-model="contractor.contact_person" class="input" maxlength="120">
                <p class="error" x-show="errors.contact_person" x-text="errors.contact_person?.[0]"></p>
            </div>
            <div>
                <label class="label" for="contractor-phone">{{ __('Mobile') }}</label>
                <input id="contractor-phone" x-model="contractor.phone" class="input" inputmode="tel" maxlength="20">
                <p class="error" x-show="errors.phone" x-text="errors.phone?.[0]"></p>
            </div>
            <div>
                <label class="label" for="contractor-email">{{ __('Email') }}</label>
                <input id="contractor-email" x-model="contractor.email" type="email" class="input" maxlength="190">
                <p class="error" x-show="errors.email" x-text="errors.email?.[0]"></p>
            </div>
            <div>
                <label class="label" for="contractor-gstin">{{ __('GSTIN') }}</label>
                <input id="contractor-gstin" x-model="contractor.gstin" class="input num uppercase" maxlength="15">
                <p class="error" x-show="errors.gstin" x-text="errors.gstin?.[0]"></p>
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="contractor-address">{{ __('Address') }}</label>
                <input id="contractor-address" x-model="contractor.address" class="input" maxlength="500">
                <p class="error" x-show="errors.address" x-text="errors.address?.[0]"></p>
            </div>
        </div>
        <p class="error px-4 pb-3" x-show="failed" role="alert">{{ __('Could not save. Check your connection and try again.') }}</p>
        <div class="flex justify-end gap-2 border-t border-rule px-4 py-3">
            <button type="button" class="btn btn-ghost" @click="hide()">{{ __('Cancel') }}</button>
            <button class="btn btn-primary" :disabled="saving">{{ __('Add contractor') }}</button>
        </div>
    </form>
</div>
