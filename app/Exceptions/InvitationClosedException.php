<?php

namespace App\Exceptions;

use RuntimeException;

/** An invitation link that can no longer be used. The message is shown to the invitee. */
class InvitationClosedException extends RuntimeException {}
