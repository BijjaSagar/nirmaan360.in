{{-- Size, time and budget of a project, on the project form. The budget is money: only money.overview sees or changes it. --}}
<section class="card">
    <div class="card-head"><h2 class="card-title">{{ __('Size, time and budget') }}</h2></div>
    <div class="grid gap-4 p-4 sm:grid-cols-2">
        <x-field :label="__('Plot size')" name="plot_size" :hint="__('For example 2,400 sq ft or 30 × 80 ft.')">
            <input id="plot_size" name="plot_size" value="{{ old('plot_size', $project->plot_size) }}" class="input" maxlength="60">
        </x-field>
        <x-field :label="__('Built-up area')" name="built_up_area" :hint="__('For example 6,200 sq ft over all floors.')">
            <input id="built_up_area" name="built_up_area" value="{{ old('built_up_area', $project->built_up_area) }}" class="input" maxlength="60">
        </x-field>
        <x-field :label="__('Planned days')" name="planned_days" :hint="__('Counted from the start date. Used to tell if work is behind time.')">
            <input id="planned_days" name="planned_days" type="number" inputmode="numeric" min="1" max="5000" value="{{ old('planned_days', $project->planned_days) }}" class="input num">
        </x-field>
        <x-field :label="__('Deadline')" name="deadline_date">
            <input id="deadline_date" name="deadline_date" type="date" value="{{ old('deadline_date', $project->deadline_date?->format('Y-m-d')) }}" class="input">
        </x-field>
        @can('money.overview')
            <x-field :label="__('Approved budget (₹)')" name="budget_amount" :hint="__('Decided when the BOQ is final. Only you and others with budget access can see it.')">
                <input id="budget_amount" name="budget_amount" type="number" inputmode="decimal" step="0.01" min="0" value="{{ old('budget_amount', $project->budget_amount) }}" class="input num">
            </x-field>
            <x-field :label="__('Margin allowed over budget (%)')" name="budget_margin_pct" :hint="__('Most builders accept up to 5%.')">
                <input id="budget_margin_pct" name="budget_margin_pct" type="number" inputmode="decimal" step="0.01" min="0" max="100" value="{{ old('budget_margin_pct', $project->budget_margin_pct ?? 5) }}" class="input num">
            </x-field>
        @endcan
        @if (auth()->user()->organization->allowsFeature('site_control'))
        <x-field :label="__('BOQ style')" name="boq_mode" class="sm:col-span-2" :hint="__('Detailed is line by line. Thumb rule is a few overall lines priced per square foot. You can change this later.')">
            <select id="boq_mode" name="boq_mode" class="input">
                <option value="detailed" @selected(old('boq_mode', $project->boq_mode ?? 'detailed') === 'detailed')>{{ __('Detailed, line by line') }}</option>
                <option value="thumb_rule" @selected(old('boq_mode', $project->boq_mode) === 'thumb_rule')>{{ __('Thumb rule, overall lines') }}</option>
            </select>
        </x-field>
        @endif
    </div>
</section>
