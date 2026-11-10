<?php

namespace App\Services\Notify;

/**
 * What a push opens in the app, which switch in the person's profile it falls under, and the
 * facts that are checked again just before it is sent, which can be hours later after quiet hours.
 *
 * The app reads these from the "data" block of the push, every value a string. Always present:
 * type, id, category, event, critical ("1" or "0") and url (the web page for the same thing).
 * project_id is there when the record belongs to a project. What each type opens:
 *
 *   alert               id = alert. Open GET /projects/{project_id}/alerts and show that alert.
 *   approval            id = approval. status "waiting": open the approval to act on it. Otherwise
 *                       (approved, returned, rejected) open the document: doc_type, doc_id, doc_uuid.
 *   contractor_payment  id = payment. Open GET /contractor-payments/{id}. status "waiting" means it
 *                       needs this person: approval_id is the approval to act on.
 *   material_flag       id = material. Open GET /projects/{project_id}/material-control/check at that
 *                       material; flag names the gap (over_used, unaccounted) that needs a reason.
 *   delivery_challan    id = challan. Open GET /projects/{project_id}/material-control/challans at that
 *                       challan; material_id and flag ("short_delivery") say what needs a reason.
 *   issue               id = issue. Open the issue: uuid is its client_uuid when it has one.
 *   portal_payment      id = payment, for a contractor login. Open GET /contractor-portal/payments.
 *   portal_bill         id = bill, for a contractor login. Open GET /contractor-portal/bills/{id}.
 *   test                The admin's test push. Nothing to open.
 *   link                Anything else. Nothing to open in the app; url is the web page.
 */
final readonly class PushTarget
{
    /**
     * @param  ?string  $category  One of the PushCategory names. Null follows only the main push switch.
     * @param  string  $type  One of the types listed above.
     * @param  int|string  $id  The record of that type.
     * @param  int  $organizationId  The company the record belongs to. A push never crosses companies.
     * @param  ?int  $projectId  The project it happened on. Someone limited to other projects is not sent it.
     * @param  list<string>  $permissions  Every one must still be held when the push is sent.
     * @param  ?string  $dedupeKey  The same key is pushed to one person once a day at most. Null means every send goes out.
     * @param  array<string, scalar|null>  $extra  More ids the app needs to open the record, such as approval_id. An alert_id,
     *                                             or an approval_id with a level, is also checked before sending: the alert must
     *                                             still be open, the approval still waiting on this person at that level.
     * @param  bool  $ownMoney  The amount in the text is the recipient's own, such as a contractor's payment.
     */
    public function __construct(
        public ?string $category,
        public string $type,
        public int|string $id,
        public int $organizationId,
        public ?int $projectId = null,
        public array $permissions = [],
        public ?string $dedupeKey = null,
        public array $extra = [],
        public bool $ownMoney = false,
    ) {}
}
