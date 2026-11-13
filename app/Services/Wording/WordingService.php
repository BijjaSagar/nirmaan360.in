<?php

namespace App\Services\Wording;

use App\Models\AiTextCall;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentReading\NothingToSend;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\DocumentReading\TextCallHandlers;
use App\Services\DocumentReading\TextCalls;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Asks a model for the free sentences of a document.
 *
 * A document always stands complete without a model: every slot has its code text in English,
 * Hindi and Marathi. draft() is the optional step on top. It makes a text call that is charged
 * only when every sentence the model wrote passed the guards; a refused answer is asked for once
 * more, and after a second refusal the code text stays. The outcome reaches the feature through
 * the WordingCall it registered for the record.
 */
class WordingService
{
    /** The purpose every model-written sentence of a letter, an update or an explanation is budgeted under. */
    public const PURPOSE = 'wording';

    public function __construct(
        private TextCalls $calls,
        private TextCallHandlers $handlers,
        private TemplateLoader $templates,
    ) {}

    /**
     * Makes and queues the text call for one document. Nothing is drafted in this request: the
     * record's WordingCall is told when the call ends, with the model's sentences or with the
     * reason the code text is used. Until then, and whenever this throws, the feature shows the
     * code text.
     *
     * @param  string  $purpose  A text purpose with figures in the config. PURPOSE unless a feature has its own.
     * @param  DocumentType  $type  The document as the person sees it now.
     * @param  FactSheet  $sheet  The facts its fixed sentences state. The model is never given a value from it.
     * @param  string  $locale  'en', 'hi' or 'mr': the language of the document.
     * @param  Model  $subject  The saved record the draft belongs to. A WordingCall must be registered for it.
     * @param  array{project?: ?Project, client_uuid?: ?string}  $with  As for TextCalls::start.
     *
     * @throws AuthorizationException The person may not use the AI features, or the plan has none.
     * @throws ReadRefused not_connected.
     * @throws SpendRefused A limit, a daily budget or the wallet refused it. Nothing was made or held.
     * @throws ValidationException A client_uuid that belongs to another call.
     * @throws WordingException A slot has no usable code text in this language.
     */
    public function draft(string $purpose, DocumentType $type, FactSheet $sheet, string $locale, Model $subject, User $by, array $with = []): AiTextCall
    {
        $document = new WordingDocument($type, $sheet, $locale);

        if ($type->freeSlots() === []) {
            throw new LogicException("Document type '{$type->key}' has no sentence a model may write.");
        }

        // The code text of every slot comes first: it is what stands until the call ends, and if the model's text is not used.
        $this->templates->load($type, $locale);

        $handler = $this->handlers->get($purpose, $subject->getMorphClass());

        if (! $handler instanceof WordingCall) {
            throw new LogicException("The handler registered for \"{$purpose}\" and {$subject->getMorphClass()} is not a WordingCall.");
        }

        $asIfMade = new AiTextCall([
            'organization_id' => $subject->getAttribute('organization_id'),
            'purpose' => $purpose,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);

        // The queue builds the document again from the record. It must be the one the person is looking at.
        if (! $handler->builds($asIfMade, $document)) {
            throw new LogicException("The handler registered for {$subject->getMorphClass()} builds another document than the one a draft was asked for.");
        }

        try {
            return $this->calls->start($purpose, $subject, $by, $with);
        } catch (NothingToSend $nothing) {
            throw new LogicException('The record has nothing a model could write: '.$nothing->reason.'.', previous: $nothing);
        }
    }
}
