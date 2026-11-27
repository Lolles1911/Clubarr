<?php

namespace App\Services;

use RuntimeException;

/** The invitation can't be used (withdrawn, expired, used up); the message is for the visitor. */
class InvitationProblem extends RuntimeException {}
