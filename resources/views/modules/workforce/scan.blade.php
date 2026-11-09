@extends('layouts.app')
@section('title', __('Scan attendance'))
@section('content')
    <x-page-header :title="__('Scan attendance')" :subtitle="$project->name" />

    <script>
        // Reads QR cards with the phone camera where the browser can, and always accepts a typed or
        // handheld-scanner code. Both send the code to the same endpoint the mobile API uses.
        function qrScan(config) {
            return {
                supported: 'BarcodeDetector' in window && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
                running: false, stream: null, timer: null, busy: false, manual: '',
                message: null, log: [], lastCode: '', lastAt: 0,
                async start() {
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                        this.$refs.video.srcObject = this.stream;
                        await this.$refs.video.play();
                        const detector = new BarcodeDetector({ formats: ['qr_code'] });
                        this.running = true;
                        this.timer = setInterval(async () => {
                            if (this.busy || !this.running) return;
                            const found = await detector.detect(this.$refs.video).catch(() => []);
                            if (found.length && (found[0].rawValue !== this.lastCode || Date.now() - this.lastAt > 4000)) {
                                this.lastCode = found[0].rawValue; this.lastAt = Date.now();
                                this.send(found[0].rawValue);
                            }
                        }, 300);
                    } catch (e) {
                        this.message = { ok: false, text: config.text.camera };
                    }
                },
                stop() {
                    this.running = false; clearInterval(this.timer);
                    if (this.stream) this.stream.getTracks().forEach(t => t.stop());
                },
                async send(code) {
                    code = (code || '').trim();
                    if (!code || this.busy) return;
                    this.busy = true;
                    try {
                        const res = await fetch(config.url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.token },
                            body: JSON.stringify({ code, client_uuid: crypto.randomUUID ? crypto.randomUUID() : null }),
                        });
                        const body = await res.json().catch(() => ({}));
                        if (res.ok) {
                            const text = body.already_marked ? config.text.already : config.text.marked;
                            this.message = { ok: true, text: body.worker.name + ' · ' + text };
                            this.log.unshift({ name: body.worker.name, trade: body.worker.trade, note: text, time: new Date().toLocaleTimeString() });
                            if (navigator.vibrate) navigator.vibrate(80);
                        } else {
                            this.message = { ok: false, text: (body.errors && body.errors.code && body.errors.code[0]) || body.message || config.text.failed };
                        }
                    } catch (e) {
                        this.message = { ok: false, text: config.text.offline };
                    }
                    this.manual = ''; this.busy = false;
                },
            };
        }
    </script>

    <div class="grid gap-5 lg:grid-cols-2"
         x-data="qrScan({ url: '{{ route('workforce.scan.store', $project) }}', token: '{{ csrf_token() }}', text: {
             camera: @js(__('The camera could not be opened. Allow camera access, or type the code below.')),
             marked: @js(__('marked present')),
             already: @js(__('already marked today')),
             failed: @js(__('The card could not be read.')),
             offline: @js(__('No connection. Try again when you are online.')),
         } })"
         x-on:beforeunload.window="stop()">
        <div class="card p-4">
            <p class="mb-3 text-sm text-ink-3">{{ __('Hold a worker card in front of the camera. Each scan marks the worker present for today.') }}</p>

            <template x-if="supported">
                <div>
                    <video x-ref="video" class="aspect-square w-full max-w-sm rounded-[3px] bg-ink object-cover" playsinline muted x-show="running"></video>
                    <div class="mt-3 flex gap-2">
                        <button type="button" class="btn btn-primary" x-show="!running" @click="start()">{{ __('Start camera') }}</button>
                        <button type="button" class="btn btn-ghost" x-show="running" x-cloak @click="stop()">{{ __('Stop camera') }}</button>
                    </div>
                </div>
            </template>
            <p x-show="!supported" x-cloak class="rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm">{{ __('This browser cannot read QR codes with the camera. Use a handheld scanner, or type the code below.') }}</p>

            <form class="mt-4" @submit.prevent="send(manual)">
                <label class="label" for="code">{{ __('Card code') }}</label>
                <div class="flex gap-2">
                    <input id="code" x-model="manual" class="input num" autocomplete="off" autocapitalize="off" placeholder="N360W-…">
                    <button class="btn btn-ink" :disabled="busy">{{ __('Mark present') }}</button>
                </div>
            </form>

            <p x-show="message" x-cloak role="status" class="mt-4 rounded-[3px] border px-3 py-2 text-sm font-medium"
               :class="message && message.ok ? 'border-moss bg-moss/10 text-moss' : 'border-laterite bg-laterite/10 text-laterite-dark'" x-text="message && message.text"></p>
        </div>

        <div class="card overflow-x-auto">
            <div class="card-head"><span class="card-title">{{ __('Scanned today') }}</span></div>
            <table class="ledger">
                <thead><tr><th>{{ __('Worker') }}</th><th>{{ __('Trade') }}</th><th>{{ __('Time') }}</th></tr></thead>
                <tbody>
                <template x-for="row in log" :key="row.time + row.name">
                    <tr><td class="font-semibold" x-text="row.name"></td><td x-text="row.trade"></td><td class="num text-xs" x-text="row.time + ' · ' + row.note"></td></tr>
                </template>
                @foreach ($recent as $mark)
                    <tr>
                        <td class="font-semibold">{{ $mark->worker?->name }}</td>
                        <td>{{ __($mark->worker?->trade ?? '') }}</td>
                        <td class="num text-xs">{{ $mark->updated_at->format('h:i A') }}</td>
                    </tr>
                @endforeach
                @if ($recent->isEmpty())
                    <tr x-show="log.length === 0"><td colspan="3" class="py-6 text-center text-ink-3">{{ __('No cards scanned yet today.') }}</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
@endsection
