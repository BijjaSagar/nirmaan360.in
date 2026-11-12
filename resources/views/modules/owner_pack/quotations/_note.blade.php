{{--
    The cover note, one paragraph per sentence block. $slots: the pieces from Rendered::parts() to print.
    $marked: on the review screen every figure the app placed is marked with what it is, and every number
    a person typed is marked as typed. On the shared page the same text is printed plain.
    The pieces are written out in one PHP block so that no space creeps in between a figure and the
    full stop after it. Every piece is escaped.
--}}
@foreach ($slots as $slot)
    <p class="whitespace-pre-line" data-slot="{{ $slot['id'] }}">@php
        foreach ($slot['parts'] as $part) {
            if ($marked && $part['fact'] !== null) {
                echo '<mark class="rounded-[2px] bg-haldi/35 px-0.5" title="'.e(\App\Services\OwnerPack\QuotationDocument::label($part['fact'])).'">'.e($part['text']).'</mark>';
            } elseif ($marked && $part['typed'] !== null) {
                echo '<mark class="rounded-[2px] bg-laterite/15 px-0.5" title="'.e(\App\Services\Wording\FinalCheck::typedLabel()).'">'.e($part['text']).'</mark>';
            } else {
                echo e($part['text']);
            }
        }
    @endphp</p>
@endforeach
