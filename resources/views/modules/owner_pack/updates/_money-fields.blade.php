{{--
    The payment position of an update: shown only to someone who may see money. $update is null on the new form.
    $quotations: the issued quotations of the project that no later issued revision has replaced.
--}}
@php
    $withMoney = (bool) old('show_money', $update?->show_money ?? false);
    $chosen = (string) old('quotation_id', $update?->quotation_id ?? ($quotations->count() === 1 ? $quotations->first()->id : ''));
@endphp
<div class="sm:col-span-2 lg:col-span-3">
    @if ($quotations->isEmpty())
        <p class="text-sm text-ink-3">{{ __('The payment position can be added once this project has an issued quotation.') }}</p>
    @else
        <input type="hidden" name="show_money" value="0">
        <label class="flex cursor-pointer items-start gap-2 text-sm">
            <input type="checkbox" name="show_money" value="1" class="mt-1" @checked($withMoney)>
            <span>{{ __('State the payment position: the stages reached and what is due') }}</span>
        </label>
        @error('show_money')<p class="error">{{ $message }}</p>@enderror
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <x-field :label="__('On which quotation')" name="quotation_id">
                <select id="quotation_id" name="quotation_id" class="input">
                    <option value="">{{ __('Choose a quotation') }}</option>
                    @foreach ($quotations as $quotation)
                        <option value="{{ $quotation->id }}" @selected($chosen === (string) $quotation->id)>{{ $quotation->number }} · {{ __('Revision :n', ['n' => $quotation->revision]) }} · {{ $quotation->client_name }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('Received so far')" name="received_amount" :hint="__('Type what the client has paid so far. The update prints it as entered by the contractor. Left empty, the update says nothing about what is due.')">
                <input id="received_amount" name="received_amount" value="{{ old('received_amount', $update?->received_amount) }}" inputmode="decimal" class="input num" autocomplete="off">
            </x-field>
        </div>
    @endif
</div>
